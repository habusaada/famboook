<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\AddFamilyMemberAction;
use App\Actions\FamilyPortal\AdmitRegistryChildAction as Action;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin as Pin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\NationalIdGuard;
use App\Support\RelationshipTypes;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\SyntheticReleasePinFixture as Fixture;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2c: real concurrent admissions on PostgreSQL. Each admission
 * runs in its own PHP process (tests/Support/FamilyPortal/admission_worker.php); a holder
 * session of this test blocks a lock so the processes really wait on each other.
 *
 * Admissions commit, and the admission tables then refuse TRUNCATE (ADR §9a), so this class
 * uses no truncating trait: it rebuilds the schema with migrate:fresh before and after each
 * test, leaving an empty, migrated database for the next class. Synthetic data only.
 */
class AdmitRegistryChildConcurrencyTest extends TestCase
{
    use FamilyIdentityFixtures;

    private const RELEASE = 7;

    private const DECLARATION = '2026-11-v1';

    private Fixture $fixture;

    private Connection $admin;

    private AdmissionRegistrySource $source;

    /** @var array<int, array{0: resource, 1: list<string>}> process and its spec, stdout and stderr files */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Concurrent admissions need PostgreSQL.');
        }
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        config(['family_registry_admission.mode' => 'ON', 'family_registry_admission.declaration_version' => self::DECLARATION]);
        $this->fixture = new Fixture;
        $this->admin = Fixture::connection('admin');
        $this->fixture->install($this->admin, self::RELEASE);
        $this->source = new AdmissionRegistrySource(self::RELEASE);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            foreach ($this->workers as [$process, $files]) {
                proc_terminate($process);
                proc_close($process);
                array_map(fn ($f) => @unlink($f), $files);
            }
            foreach (array_keys(config('database.connections')) as $name) {
                if (str_starts_with($name, 'synthetic_pin_') && $name !== 'synthetic_pin_admin') {
                    DB::disconnect($name);
                }
            }
            $this->fixture->uninstall($this->admin);
            DB::disconnect('synthetic_pin_admin');
            $this->artisan('migrate:fresh');
        }
        parent::tearDown();
    }

    /** @return array{user: User, person: Person, family: Family, context: FamilyAccessResult} */
    private function household(string $nationalId): array
    {
        $head = $this->activatedHead($nationalId);
        $head['user']->givePermissionTo(Action::PERMISSION);
        $head['context'] = app(FamilyAccessResolver::class)->familyContext($head['user']);

        return $head;
    }

    /** Starts one admission in its own process. */
    private function spawn(array $head, string $child, ?string $key = null, ?array $source = null, string $link = 'FATHER', ?array $barrier = null, ?int $travel = null): int
    {
        $file = tempnam(sys_get_temp_dir(), 'admission');
        file_put_contents($file, json_encode([
            'config' => [
                'family_registry_admission.mode' => 'ON',
                'family_registry_admission.declaration_version' => self::DECLARATION,
                'family_auth.fingerprint' => config('family_auth.fingerprint'),
                'app.key' => config('app.key'),
                // The test's own connection, exactly: an empty password is dropped from a Windows
                // environment, and the worker would then read the development .env instead.
                'database.default' => 'pgsql',
                'database.connections.pgsql' => config('database.connections.pgsql'),
            ],
            'user' => $head['user']->id,
            'source' => $source ?? $this->source->toArray(),
            'ref' => Refs::issue($head['context'], self::RELEASE, $child, $link),
            'key' => $key ?? (string) Str::uuid(),
            'declaration' => self::DECLARATION,
            ...($barrier === null ? [] : ['ready' => $barrier[0], 'go' => $barrier[1]]),
            ...($travel === null ? [] : ['travel' => $travel]),
        ]));
        $db = config('database.connections.pgsql');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) ($db['password'] ?? ''), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr',
        ]);
        // Files, not pipes: a worker must never block on a full pipe the test is not reading.
        $out = tempnam(sys_get_temp_dir(), 'admission-out');
        $err = tempnam(sys_get_temp_dir(), 'admission-err');
        $process = proc_open([PHP_BINARY, base_path('tests/Support/FamilyPortal/admission_worker.php'), $file],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->workers[] = [$process, [$file, $out, $err]];

        return array_key_last($this->workers);
    }

    /** @return array<string, mixed> the worker's outcome; its stderr (logs) is checked for identity data */
    private function outcomeOf(int $worker, array $secrets = []): array
    {
        [$process, $files] = $this->workers[$worker];
        $deadline = microtime(true) + 60;
        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                $this->fail('A worker did not finish in 60 s: '.file_get_contents($files[2]));
            }
            usleep(50000);
        }
        $code = $status['exitcode'];
        proc_close($process);
        [$out, $err] = [(string) file_get_contents($files[1]), (string) file_get_contents($files[2])];
        unset($this->workers[$worker]);
        array_map(fn ($f) => @unlink($f), $files);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $out.$err, 'a worker printed or logged identity data');
        }
        $this->assertSame(0, $code, "worker failed: {$err}");

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Waits until $count other sessions wait for a lock. */
    private function waitingOnLocks(int $count): void
    {
        $deadline = microtime(true) + 20;
        while ((int) $this->admin->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()
                AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()") < $count) {
            if (microtime(true) > $deadline) {
                $this->fail("Timed out waiting for {$count} blocked session(s).");
            }
            usleep(50000);
        }
    }

    private function child(string $nationalId, array $fields): void
    {
        $this->source->add($nationalId, $fields);
    }

    private function activeMemberships(string $nationalId): int
    {
        return FamilyMembership::query()->where('is_active', true)
            ->whereIn('person_id', Person::query()->where('national_id', $nationalId)->select('id'))->count();
    }

    // ---------------------------------------------------------------- one key, one child

    public function test_one_key_twice_at_once_admits_once_and_replays_once(): void
    {
        $head = $this->household('123456789');
        $this->child('401234567', ['fatherid' => '123456789']);
        $holder = Fixture::connection('holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$head['family']->id]);

        $key = (string) Str::uuid();
        $a = $this->spawn($head, '401234567', $key);
        $b = $this->spawn($head, '401234567', $key);
        $this->waitingOnLocks(2);
        $holder->commit();

        $results = [$this->outcomeOf($a, ['401234567']), $this->outcomeOf($b, ['401234567'])];
        usort($results, fn ($x, $y) => $x['replayed'] <=> $y['replayed']);
        $this->assertSame(['ADMITTED', false], [$results[0]['result'], $results[0]['replayed']]);
        $this->assertSame(['ADMITTED', true], [$results[1]['result'], $results[1]['replayed']]);
        $this->assertSame($results[0]['member_ref'], $results[1]['member_ref']);
        $this->assertSame(1, DB::table('family_registry_admissions')->where('client_reference', $key)->count());
        $this->assertSame(1, $this->activeMemberships('401234567'));
        $this->assertSame(1, Person::query()->where('national_id', '401234567')->count());
    }

    public function test_two_families_admitting_the_same_child_at_once_admit_once(): void
    {
        $father = $this->household('223456789');
        $mother = $this->household('323456789');
        $this->child('402234567', ['fatherid' => '223456789', 'motherid' => '323456789']);
        $holder = Fixture::connection('holder');
        $holder->beginTransaction();
        $holder->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:402234567']);

        $a = $this->spawn($father, '402234567');
        $b = $this->spawn($mother, '402234567', link: 'MOTHER');
        $this->waitingOnLocks(2);
        $holder->commit();

        $results = array_column([$this->outcomeOf($a, ['402234567']), $this->outcomeOf($b, ['402234567'])], 'result');
        sort($results);
        $this->assertSame(['ADMITTED', 'NOT_ELIGIBLE'], $results);
        $this->assertSame(1, $this->activeMemberships('402234567'));
        $this->assertSame(1, Person::query()->where('national_id', '402234567')->count());
        $this->assertSame(1, DB::table('family_registry_admissions')->count());
        $this->assertSame(1, DB::table('family_registry_admission_events')->where('event_type', 'ADMISSION_REFUSED')
            ->whereIn('outcome_code', ['ACTIVE_MEMBERSHIP_ELSEWHERE', 'LOST_RACE'])->count());
    }

    // ---------------------------------------------------------------- publication and timeouts

    public function test_a_publication_waits_for_an_admission_and_a_stale_release_is_refused(): void
    {
        $first = $this->household('123456789');
        $second = $this->household('223456789');
        $this->child('403234567', ['fatherid' => '123456789']);
        $this->child('404234567', ['fatherid' => '223456789']);
        $holder = Fixture::connection('holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$first['family']->id]);

        // B is booted first and held at a barrier: a process boot is slower than A's 3 s lock timeout.
        $ready = sys_get_temp_dir().'/admission-ready-'.Str::random(8);
        $go = sys_get_temp_dir().'/admission-go-'.Str::random(8);
        $bWorker = $this->spawn($second, '404234567', barrier: [$ready, $go]);
        $deadline = microtime(true) + 60;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($ready);

        // A pins release 7, then waits for its Family.
        $a = $this->spawn($first, '403234567');
        $this->waitingOnLocks(1);

        // A publication in another session waits for A's pin.
        $publisher = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $pdo = new PDO($argv[1], $argv[2], $argv[3]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            try { $pdo->beginTransaction(); $pdo->query('SELECT '.$argv[4].'.publish(8, 15000)'); $pdo->commit(); echo 'PUBLISHED'; }
            catch (PDOException $e) { echo 'FAILED:'.($e->errorInfo[0] ?? '?'); }
            PHP, '--', 'pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname='.config('database.connections.pgsql.database'),
            (string) config('database.connections.pgsql.username'), (string) (config('database.connections.pgsql.password') ?? ''), $this->fixture->schema()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $publisherPipes);
        $this->waitingOnLocks(2);

        // B, while the publication waits: the pin is busy, retryable, nothing written.
        touch($go);
        $b = $this->outcomeOf($bWorker, ['404234567']);
        @unlink($ready);
        @unlink($go);
        $this->assertSame(['UNAVAILABLE', true], [$b['result'], $b['retryable']]);
        $this->assertSame(0, Person::query()->where('national_id', '404234567')->count());
        $this->assertSame(self::RELEASE, $this->fixture->activeRelease($this->admin), 'not published while A holds the pin');

        $holder->commit();
        $this->assertSame(['ADMITTED', false], [($r = $this->outcomeOf($a, ['403234567']))['result'], $r['replayed']]);
        $published = stream_get_contents($publisherPipes[1]);
        fclose($publisherPipes[1]);
        fclose($publisherPipes[2]);
        proc_close($publisher);
        $this->assertSame('PUBLISHED', $published);
        $this->assertSame(8, $this->fixture->activeRelease($this->admin));

        // C read the registry before the publication (its source still says 7): the pin refuses it.
        $c = $this->outcomeOf($this->spawn($second, '404234567'), ['404234567']);
        $this->assertSame('RELEASE_CHANGED', $c['result']);
        $this->assertSame(0, Person::query()->where('national_id', '404234567')->count());
        $this->assertSame(1, DB::table('family_registry_admissions')->count());
    }

    public function test_a_lock_timeout_fails_closed_with_nothing_written(): void
    {
        $head = $this->household('123456789');
        $this->child('405234567', ['fatherid' => '123456789']);
        $holder = Fixture::connection('holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$head['family']->id]);

        $started = microtime(true);
        $result = $this->outcomeOf($this->spawn($head, '405234567'), ['405234567']);
        $holder->rollBack();

        $this->assertSame(['UNAVAILABLE', true], [$result['result'], $result['retryable']]);
        $this->assertGreaterThan(2.5, microtime(true) - $started, 'it waited for the 3 s lock timeout');
        $this->assertSame(0, Person::query()->where('national_id', '405234567')->count());
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
        $this->assertSame(0, (int) $this->admin->scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = ?::oid AND objid = ?::oid",
            Fixture::split(Pin::PIN_KEY)), 'the pin ended with the rolled-back transaction');

        // The expired-reference replay path waits for the Family too, and is bounded the same way.
        $holder->beginTransaction();
        $holder->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$head['family']->id]);
        $started = microtime(true);
        $expired = $this->outcomeOf($this->spawn($head, '405234567', travel: Refs::TTL_SECONDS + 60), ['405234567']);
        $holder->rollBack();
        $this->assertSame(['UNAVAILABLE', true], [$expired['result'], $expired['retryable']]);
        $this->assertGreaterThan(2.5, microtime(true) - $started);
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
    }

    // ---------------------------------------------------------------- existing writers

    public function test_existing_family_and_person_writers_serialize_with_an_admission(): void
    {
        $head = $this->household('123456789');
        $other = $this->household('223456789');
        $this->child('406234567', ['fatherid' => '123456789']);
        $this->child('407234567', ['fatherid' => '123456789']);

        // 1. A Staff add of the same child elsewhere holds the National ID lock (Family → National ID → Person):
        //    the admission waits behind it, then refuses an active member elsewhere.
        $worker = null;
        DB::transaction(function () use ($other, $head, &$worker) {
            NationalIdGuard::lock('406234567');
            $worker = $this->spawn($head, '406234567');
            $this->waitingOnLocks(1);
            app(AddFamilyMemberAction::class)->handle($other['family'], ['full_name' => 'اسم-تجريبي', 'national_id' => '406234567', 'gender' => 'MALE',
                'relationship_type_id' => RelationshipTypes::required('SON')], null);
        });
        $this->assertSame(['NOT_ELIGIBLE', 'ACTIVE_MEMBERSHIP_ELSEWHERE'], array_values(array_intersect_key($this->outcomeOf($worker, ['406234567']), ['result' => 1, 'audit' => 1])));
        $this->assertSame(1, $this->activeMemberships('406234567'));

        // 2. A Staff add of the same child in the SAME Family holds the Family first (as a Change Request APPLY):
        //    the admission waits for the Family, then finds the child already a member.
        DB::transaction(function () use ($head, &$worker) {
            DB::select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$head['family']->id]);
            $worker = $this->spawn($head, '407234567');
            $this->waitingOnLocks(1);
            app(AddFamilyMemberAction::class)->handle($head['family'], ['full_name' => 'اسم-تجريبي', 'national_id' => '407234567', 'gender' => 'MALE',
                'relationship_type_id' => RelationshipTypes::required('SON')], null);
        });
        $this->assertSame(['NOT_ELIGIBLE', 'ALREADY_MEMBER'], array_values(array_intersect_key($this->outcomeOf($worker, ['407234567']), ['result' => 1, 'audit' => 1])));
        $this->assertSame(1, $this->activeMemberships('407234567'));
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
    }
}
