<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\LifeStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Deadlock regressions for ADD_FAMILY_MEMBER APPLY on PostgreSQL (docs/11
 * FP-ADR-076, lock order Family → National ID advisory lock → Person).
 *
 * Each test runs a REAL Staff Domain Action in a SEPARATE PHP process
 * (tests/Support/ChangeRequests/concurrent-actor.php) while this process
 * runs APPLY, so two transactions genuinely interleave. A third process holds
 * the National ID advisory lock as a gate, so both queue in a known order:
 *
 * 1. CorrectNationalIdAction on the matched Person. Before the fix it took
 *    the Person row, then the ID lock — the reverse of APPLY (ID lock, then
 *    Person) — and PostgreSQL aborted one of them (40P01).
 * 2. The Staff AddFamilyMemberAction for the same Family and ID. Before the
 *    fix it took the ID lock, then (membership insert) a KEY SHARE on the
 *    Family row — the reverse of APPLY (Family FOR UPDATE, then ID lock).
 *
 * With one lock order everywhere neither interleaving can deadlock: one
 * transaction waits, the other completes, and the registry checks decide.
 *
 * PostgreSQL ONLY, against a *_test database. Synthetic data only.
 */
class AddFamilyMemberDeadlockTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const NID = '401234567';

    private const ACTOR = __DIR__.'/../../../Support/ChangeRequests/concurrent-actor.php';

    private const DEADLOCK = '40P01';

    private FamilyAccessResult $context;

    private User $reviewer;

    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
        ]));
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
        // The children connect to the SAME isolated test database.
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'), 'DB_URL' => '', 'APP_ENV' => 'testing'] as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child['process']);
            proc_close($child['process']);
        }
        if (isset($this->app)) {
            DB::statement('RESET lock_timeout');
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'),
                $env('DB_PASSWORD'),
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** Starts the actor process and waits for its READY line. */
    private function spawn(string ...$args): int
    {
        $process = proc_open([PHP_BINARY, self::ACTOR, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        $this->children[] = ['process' => $process, 'pipes' => $pipes];
        $ready = trim((string) fgets($pipes[1]));
        if ($ready !== 'READY') {
            // Only now read stderr (reading it earlier would block on a live actor).
            $this->fail("actor did not start ({$ready}): ".stream_get_contents($pipes[2]));
        }

        return count($this->children) - 1;
    }

    /** Lets a READY actor proceed after $milliseconds (the gate releases; the others act). */
    private function go(int $child, int $milliseconds): void
    {
        fwrite($this->children[$child]['pipes'][0], $milliseconds.PHP_EOL);
        fflush($this->children[$child]['pipes'][0]);
    }

    /** The actor's final line ("OK" or "FAIL …"). */
    private function outcomeOf(int $child): string
    {
        return trim((string) fgets($this->children[$child]['pipes'][1]));
    }

    private function approved(): ChangeRequest
    {
        $request = app(SubmitChangeRequestAction::class)->handle($this->context, new ChangeRequestSubmission(ChangeRequestType::ADD_FAMILY_MEMBER, [
            'full_name' => 'فرد تجريبي', 'national_id' => self::NID, 'gender' => 'MALE', 'relationship' => 'SON',
        ], null, (string) Str::uuid()))->request;
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::NID,
        ));

        return $request->fresh();
    }

    /** APPLY in this process; returns what it threw (NULL on success). */
    private function apply(ChangeRequest $request): ?Throwable
    {
        DB::statement("SET lock_timeout = '20s'");
        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    public function test_apply_and_a_staff_national_id_correction_of_the_matched_person_never_deadlock(): void
    {
        $person = Person::factory()->create(['national_id' => '401-234-567', 'life_status' => LifeStatus::ALIVE, 'is_active' => true]);
        $request = $this->approved();

        $gate = $this->spawn('gate', self::NID);
        $correction = $this->spawn('correct', (string) $person->id, self::NID);
        // APPLY queues on the ID lock now; the correction starts 1.5 s later
        // (before the fix: it locked the Person, then queued on the ID lock);
        // the gate releases at 4 s, giving the ID lock to APPLY first.
        $this->go($gate, 4000);
        $this->go($correction, 1500);
        $applied = $this->apply($request);

        $outcome = $this->outcomeOf($correction);
        $this->assertStringNotContainsString(self::DEADLOCK, $outcome, 'the correction was aborted as a deadlock victim');
        $this->assertNull($applied, 'APPLY failed: '.($applied ? $applied::class.' '.($applied instanceof ChangeRequestException ? $applied->reason : '') : ''));
        $this->assertSame(S::APPLIED, $request->fresh()->status);
        $this->assertSame($this->context->family->id, $person->fresh()->activeMembership()->sole()->family_id);
        // The correction either completed after APPLY or was refused safely (the
        // Person's family changed while it waited) — never a deadlock.
        $this->assertContains($outcome, ['OK', 'FAIL Illuminate\Validation\ValidationException']);
    }

    public function test_apply_and_a_staff_add_member_of_the_same_id_to_the_same_family_never_deadlock(): void
    {
        $request = $this->approved();

        $gate = $this->spawn('gate', self::NID);
        $staff = $this->spawn('add-member', (string) $this->context->family->id, self::NID);
        // The Staff action starts first and queues on the ID lock; APPLY then
        // locks the Family and queues behind it; the gate releases at 3 s.
        $this->go($staff, 0);
        usleep(1_500_000);
        $this->go($gate, 1500);
        $applied = $this->apply($request);

        $this->assertSame('OK', $this->outcomeOf($staff), 'the Staff registration did not complete');
        $this->assertInstanceOf(ChangeRequestException::class, $applied);
        $this->assertSame(ChangeRequestException::BASE_CHANGED, $applied->reason, 'APPLY must refuse the changed registry, not fail on a deadlock');
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }
}
