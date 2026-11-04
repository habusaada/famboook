<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRegistryReader;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\LifeVerificationStatus;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionBindings;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifeVerifier;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\GenderGateLiftedAdmissibility;
use Tests\Support\FamilyPortal\PendingGatesLiftedAdmissibility;
use Tests\Support\FamilyPortal\SyntheticDeclaration;
use Tests\Support\FamilyPortal\SyntheticLifeVerifier;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 RR-02 on PostgreSQL, against the REAL identity_ref schema
 * (00-roles, 01-schema, 08-lookup) plus 10-registry-admission-pin.dba.sql, in the
 * disposable *_test cluster, with a real throwaway lookup login:
 *
 * - DBA-1: publication waits, bounded, for an admission's pin, and an admission fails
 *   fast while a publication holds it;
 * - DBA-2: the active release pointer moves only forward, and only under the pin;
 * - DBA-3: release_pin_held() proves only a live, nonced pin, for the reader only;
 * - CivilRegistryChildSource over the real identity_ref_lookup functions;
 * - end to end over HTTP: discovery, the delivered declaration, a reference, an
 *   admission, its replay and a release change, with the civil registry bound through
 *   RegistryAdmissionBindings. G-04 and G-05 are lifted ONLY by the test-only
 *   PendingGatesLiftedAdmissibility; with the gated one nobody is admissible.
 *
 * SYNTHETIC identities only. Admission rows defeat DatabaseTruncation (the TRUNCATE
 * guard), so the schema is rebuilt around each test.
 */
class RegistryAdmissionCivilRegistryPostgresTest extends TestCase
{
    use FamilyIdentityFixtures, IdentityRefDatabase, IdentityRefStaging;

    private const HEAD = '123456789';

    private const CHILD = '401234567';

    private const SECOND = '402234567';

    private const DECLARATION = 'v-synthetic-1';

    private const DISCOVERY = '/api/v1/family/household/registry-children';

    private const ADMISSIONS = '/api/v1/family/household/registry-children/admissions';

    private const PIN = "x'4642434956303150'::bigint";

    private bool $ready = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against the disposable *_test cluster.');
        }
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        // With APP_DEBUG on, Laravel's renderer records every query with its bindings, and the admission refuses to run.
        app('events')->forget(QueryExecuted::class);
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->setUpIdentityRef();
        DB::unprepared($this->sqlFile('08-lookup.dba.sql'));
        DB::unprepared($this->sqlFile('10-registry-admission-pin.dba.sql'));
        $reader = $this->loginConnection(CivilRegistryReader::ROLE)->getConfig();
        config(['database.connections.identity_ref_lookup.username' => $reader['username'],
            'database.connections.identity_ref_lookup.password' => $reader['password']]);
        DB::purge('identity_ref_lookup');
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            DB::purge('identity_ref_lookup');
            $this->tearDownIdentityRefStaging();
            $this->tearDownIdentityRef();
            $this->artisan('migrate:fresh');
        }
        parent::tearDown();
    }

    private function active(): int
    {
        return (int) $this->asRole(self::OWNER, fn () => DB::selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1')->release_id);
    }

    /** The message of the database error $fn raises, or '' when it succeeds. */
    private function errorOf(\Closure $fn): string
    {
        try {
            $fn();

            return '';
        } catch (PDOException $e) {
            return $e->getMessage();
        }
    }

    private function intOf(PDO $db, string $sql): int
    {
        return (int) $db->query($sql)->fetchColumn();
    }

    /** A BUILDING release on top of $base, with $identities, not yet published. */
    private function building(int $base, array $identities): int
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, $base);
        foreach ($identities as $mainid => $overrides) {
            $this->present($run, $release, $this->payload((string) $mainid, $overrides));
        }
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.carry_forward_missing(?)', [$release]));

        return $release;
    }

    // ---------------------------------------------------------------- DBA-2

    public function test_dba_2_the_pointer_moves_only_forward_and_only_under_the_pin(): void
    {
        $r1 = $this->publishedRelease(['111111118' => []]);
        $r2 = $this->publishedRelease(['111111118' => []], $r1);
        $this->assertSame($r2, $this->active(), 'publication itself still works under DBA-1 and DBA-2');

        $owner = $this->connection(self::OWNER);
        $this->assertStringContainsString('IDENTITY_REF_ACTIVE_RELEASE_PIN_NOT_HELD',
            $this->errorOf(fn () => $owner->exec("UPDATE identity_ref.active_release SET release_id = {$r1} WHERE id = 1")));
        $owner->beginTransaction();
        $owner->query('SELECT pg_advisory_xact_lock('.self::PIN.')');
        $this->assertStringContainsString('IDENTITY_REF_ACTIVE_RELEASE_NOT_FORWARD',
            $this->errorOf(fn () => $owner->exec("UPDATE identity_ref.active_release SET release_id = {$r1} WHERE id = 1")));
        $owner->rollBack();
        // A shared pin (an admission's) is not enough to write.
        $owner->beginTransaction();
        $owner->query('SELECT pg_advisory_xact_lock_shared('.self::PIN.')');
        $this->assertStringContainsString('IDENTITY_REF_ACTIVE_RELEASE_PIN_NOT_HELD',
            $this->errorOf(fn () => $owner->exec("UPDATE identity_ref.active_release SET release_id = {$r2} WHERE id = 1")));
        $owner->rollBack();
        $this->assertSame($r2, $this->active());
    }

    // ---------------------------------------------------------------- DBA-1

    public function test_dba_1_publication_waits_bounded_for_a_pin_and_admissions_fail_fast_meanwhile(): void
    {
        $r1 = $this->publishedRelease(['111111118' => []]);
        $r2 = $this->building($r1, ['222222226' => []]);

        // An admission holds the pin: publication waits (here 300 ms), then gives up, changing nothing.
        $admission = $this->connection();
        $admission->beginTransaction();
        $this->assertSame(1, $this->intOf($admission, 'SELECT pg_try_advisory_xact_lock_shared('.self::PIN.')::int'));
        $publisher = $this->connection(self::WRITER);
        $publisher->exec("SET famboook.publication_pin_wait = '300ms'");
        $started = microtime(true);
        $this->assertStringContainsString('IDENTITY_REF_PUBLICATION_PINNED',
            $this->errorOf(fn () => $publisher->query("SELECT identity_ref.publish_release({$r2})")));
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertSame($r1, $this->active());
        $admission->commit();

        // Once free, publication takes the pin exclusively until commit, and restores the caller's lock_timeout.
        $publisher->beginTransaction();
        $publisher->exec("SET LOCAL lock_timeout = '1234ms'");
        $publisher->query("SELECT identity_ref.publish_release({$r2})");
        $this->assertSame('1234ms', (string) $publisher->query("SELECT current_setting('lock_timeout')")->fetchColumn());
        $late = $this->connection();
        $late->beginTransaction();
        $this->assertSame(0, $this->intOf($late, 'SELECT pg_try_advisory_xact_lock_shared('.self::PIN.')::int'), 'an admission fails fast');
        $late->rollBack();
        $publisher->commit();
        $this->assertSame($r2, $this->active());
    }

    // ---------------------------------------------------------------- DBA-3

    public function test_dba_3_proves_only_a_live_nonced_pin_and_only_to_the_reader(): void
    {
        $r1 = $this->publishedRelease(['111111118' => []]);
        $holder = $this->connection();
        $holder->beginTransaction();
        $row = $holder->query('SELECT pg_try_advisory_xact_lock_shared('.self::PIN.')::int AS pin,
            pg_try_advisory_xact_lock(1178751054, 4242)::int AS nonce, pg_backend_pid() AS pid')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([1, 1], [(int) $row['pin'], (int) $row['nonce']]);
        $pid = (int) $row['pid'];

        $reader = new CivilRegistryReader;
        $held = $reader->releasePinHeld($pid, 4242);
        $this->assertTrue($held->held);
        $this->assertSame($r1, $held->activeReleaseId);
        $this->assertFalse($reader->releasePinHeld($pid, 4243)->held, 'another nonce');
        $this->assertFalse($reader->releasePinHeld($pid + 100000, 4242)->held, 'another backend');
        $holder->commit();
        $this->assertFalse($reader->releasePinHeld($pid, 4242)->held, 'the transaction ended');

        $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::APP,
            fn () => DB::select('SELECT * FROM identity_ref_lookup.release_pin_held(1, 1)'))), 'the application role cannot call it');
    }

    // ---------------------------------------------------------------- the adapter

    public function test_the_adapter_reads_the_published_release_through_the_lookup_functions(): void
    {
        $identities = [self::HEAD => ['fullname' => 'رب-تجريبي'], '999999990' => []];
        for ($i = 0; $i < 30; $i++) {
            $identities[(string) (410000000 + $i)] = ['fatherid' => self::HEAD];
        }
        $identities['420000001'] = ['motherid' => self::HEAD];
        $r1 = $this->publishedRelease($identities);
        $source = new CivilRegistryChildSource(new CivilRegistryReader);

        $page = $source->childrenOf(self::HEAD, 0, 100);
        $this->assertSame([$r1, 31, 31], [$page->releaseId, $page->total, count($page->rows)]);
        $this->assertSame([$r1], array_values(array_unique(array_map(fn ($r) => $r->releaseId, $page->rows))));
        $this->assertNotContains('999999990', array_map(fn ($r) => $r->mainid, $page->rows));
        $this->assertSame(self::HEAD, $source->recordOf(self::HEAD, $r1)?->mainid);
        $this->assertNull($source->recordOf('488888888', $r1));

        $this->publishedRelease([], $r1);
        try {
            $source->recordOf(self::HEAD, $r1);
            $this->fail('A record of a replaced release must be RELEASE_CHANGED.');
        } catch (CivilLookupException $e) {
            $this->assertSame(CivilLookupException::RELEASE_CHANGED, $e->reason);
        }
    }

    // ---------------------------------------------------------------- end to end

    public function test_discovery_declaration_admission_replay_and_release_change_over_the_published_registry(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $child = ['fatherid' => self::HEAD, 'gender' => 'ذكر', 'datebirth' => '2010-01-15T00:00:00', 'maritalst' => 'اعزب'];
        $r1 = $this->publishedRelease([self::HEAD => ['fullname' => 'رب-تجريبي'],
            self::CHILD => [...$child, 'fullname' => 'ابن-تجريبي أول'], self::SECOND => [...$child, 'fullname' => 'ابن-تجريبي ثان']]);
        $head = $this->activatedHead(self::HEAD);
        $head['user']->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        config(['family_registry_admission.mode' => 'ON', 'family_registry_admission.civil_registry_binding' => true,
            ...SyntheticDeclaration::approved(self::DECLARATION)]);
        RegistryAdmissionBindings::register($this->app);
        $as = fn () => $this->actingAs($head['user']->fresh());

        // As shipped (gated admissibility, no life verifier): listed, nobody admissible, no reference; the declaration is delivered.
        $listed = $as()->getJson(self::DISCOVERY)->assertOk();
        $this->assertSame(['NOT_ELIGIBLE', 'NOT_ELIGIBLE'], array_column($listed->json('data.children'), 'status'));
        $this->assertSame([null, null], array_column($listed->json('data.children'), 'ref'));
        $this->assertSame(['version' => self::DECLARATION, 'text' => SyntheticDeclaration::TEXT, 'life_text' => null], $listed->json('data.declaration'));
        // A proven life alone opens nothing while G-04 is open.
        $life = new SyntheticLifeVerifier;
        foreach ([self::CHILD, self::SECOND] as $id) {
            $life->answer($id, LifeVerificationStatus::ALIVE, new DateTimeImmutable('@'.now()->getTimestamp()));
        }
        $this->app->instance(RegistryLifeVerifier::class, $life);
        $this->assertSame([null, null], array_column($as()->getJson(self::DISCOVERY)->assertOk()->json('data.children'), 'ref'));

        // TEST ONLY: both pending gates lifted. Discovery issues references, and one admission succeeds.
        $this->app->instance(RegistryChildAdmissibility::class, new PendingGatesLiftedAdmissibility);
        $children = $as()->getJson(self::DISCOVERY)->assertOk()->json('data.children');
        $this->assertSame(['ELIGIBLE', 'ELIGIBLE'], array_column($children, 'status'));
        $body = ['ref' => $children[0]['ref'], 'client_reference' => (string) Str::uuid(),
            'declaration_version' => self::DECLARATION, 'declaration_accepted' => true];
        $admitted = $as()->postJson(self::ADMISSIONS, $body)->assertCreated()->assertJsonPath('data.replayed', false);
        $as()->postJson(self::ADMISSIONS, $body)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.member_ref', $admitted->json('data.member_ref'));
        $evidence = DB::table('family_registry_admissions')->get();
        $this->assertCount(1, $evidence);
        $this->assertSame([$r1, self::DECLARATION], [(int) $evidence[0]->release_id, $evidence[0]->declaration_version]);
        $this->assertNotNull($evidence[0]->declared_at);
        $this->assertSame(1, DB::table('persons')->where('national_id', self::CHILD)->count());
        $this->assertSame('ALREADY_MEMBER', $as()->getJson(self::DISCOVERY)->assertOk()->json('data.children.0.status'));

        // A release published since: the other child's reference of r1 is refused, nothing changes.
        $second = $children[1]['ref'];
        $this->publishedRelease([], $r1);
        $as()->postJson(self::ADMISSIONS, [...$body, 'ref' => $second, 'client_reference' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'RELEASE_CHANGED');
        $this->assertSame(0, DB::table('persons')->where('national_id', self::SECOND)->count());

        // A draft declaration is refused even with everything else open.
        config(['family_registry_admission.declaration_version' => '2026-11-draft-1',
            'family_registry_admission.declarations' => config('family_registry_admission.declarations') + ['2026-11-draft-1' => ['approved' => false, 'text' => 'x']]]);
        $as()->postJson(self::ADMISSIONS, [...$body, 'declaration_version' => '2026-11-draft-1', 'client_reference' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'DECLARATION_REQUIRED');

        foreach ([self::CHILD, self::SECOND, self::HEAD] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $logged));
            $this->assertStringNotContainsString($secret, json_encode($children, JSON_UNESCAPED_UNICODE));
        }
    }

    // ---------------------------------------------------------------- revised life policy (RR-02A, D-06R)

    public function test_the_revised_life_policy_admits_only_on_the_heads_declaration_and_records_unknown(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $child = ['fatherid' => self::HEAD, 'gender' => 'ذكر', 'datebirth' => '2010-01-15T00:00:00', 'maritalst' => 'اعزب'];
        $dead = '403234567';
        $r1 = $this->publishedRelease([self::HEAD => ['fullname' => 'رب-تجريبي'],
            self::CHILD => [...$child, 'fullname' => 'ابن-تجريبي أول'], self::SECOND => [...$child, 'fullname' => 'ابن-تجريبي ثان'],
            $dead => [...$child, 'fullname' => 'ابن-تجريبي متوفى', 'deathdate' => '2024-03-01T00:00:00']]);
        $head = $this->activatedHead(self::HEAD);
        $head['user']->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        config(['family_registry_admission.mode' => 'ON', 'family_registry_admission.civil_registry_binding' => true,
            ...SyntheticDeclaration::approved(self::DECLARATION, withLife: true)]);
        RegistryAdmissionBindings::register($this->app);
        // TEST ONLY: G-04 lifted; life is decided by the application's own rules.
        $this->app->instance(RegistryChildAdmissibility::class, new GenderGateLiftedAdmissibility);
        $as = fn () => $this->actingAs($head['user']->fresh());
        $statuses = fn () => array_column($as()->getJson(self::DISCOVERY)->assertOk()->json('data.children'), 'status');

        // As shipped (VERIFIER_ONLY): an unproven life is never admissible, and no life statement is delivered.
        $off = $as()->getJson(self::DISCOVERY)->assertOk();
        $this->assertSame(['NOT_ELIGIBLE', 'NOT_ELIGIBLE', 'NOT_ELIGIBLE'], array_column($off->json('data.children'), 'status'));
        $this->assertNull($off->json('data.declaration.life_text'));

        // Revised policy, with r1 listed as a living-scope release extracted on 2026-10-25.
        config(SyntheticDeclaration::revisedLifePolicy(self::DECLARATION, $r1.':2026-10-25'));
        $on = $as()->getJson(self::DISCOVERY)->assertOk();
        $children = $on->json('data.children');
        $this->assertSame(['ELIGIBLE', 'ELIGIBLE', 'NOT_ELIGIBLE'], array_column($children, 'status'), 'a known death is still refused');
        $this->assertNull($children[2]['ref']);
        $this->assertSame(SyntheticDeclaration::LIFE_TEXT, $on->json('data.declaration.life_text'));

        $body = ['ref' => $children[0]['ref'], 'client_reference' => (string) Str::uuid(),
            'declaration_version' => self::DECLARATION, 'declaration_accepted' => true];
        // Without the separate declaration of current life: refused before anything is read or written.
        $as()->postJson(self::ADMISSIONS, $body)->assertStatus(422)->assertJsonPath('code', 'DECLARATION_REQUIRED');
        $this->assertSame(0, DB::table('family_registry_admissions')->count());

        $body['life_declaration_accepted'] = true;
        $as()->postJson(self::ADMISSIONS, $body)->assertCreated()->assertJsonPath('data.replayed', false);
        $as()->postJson(self::ADMISSIONS, $body)->assertOk()->assertJsonPath('data.replayed', true);

        // The evidence: the actor, the release, both declarations with their time, the basis and its approval.
        $evidence = DB::table('family_registry_admissions')->sole();
        $this->assertSame([(int) $head['user']->id, (int) $head['person']->id, $r1, self::DECLARATION, 'REGISTRY_SCOPE_DECLARATION', 'TEST-D-06R-0001', '2026-10-25'],
            [(int) $evidence->admitted_by, (int) $evidence->admitted_by_person_id, (int) $evidence->release_id, $evidence->declaration_version,
                $evidence->life_basis, $evidence->life_policy_reference, substr((string) $evidence->registry_extracted_on, 0, 10)]);
        $this->assertNotNull($evidence->declared_at);
        $this->assertNotNull($evidence->life_declared_at);
        // Never ALIVE: the declared, unverified life is recorded UNKNOWN.
        $this->assertSame('UNKNOWN', DB::table('persons')->where('national_id', self::CHILD)->value('life_status'));

        // Credible contradictory evidence refuses, even with the head's declaration.
        $verifier = (new SyntheticLifeVerifier)->answer(self::SECOND, LifeVerificationStatus::DECEASED, new DateTimeImmutable('@'.now()->getTimestamp()));
        $this->app->instance(RegistryLifeVerifier::class, $verifier);
        $this->assertSame('NOT_ELIGIBLE', $statuses()[1]);
        $as()->postJson(self::ADMISSIONS, [...$body, 'ref' => $children[1]['ref'], 'client_reference' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'NOT_ELIGIBLE');
        unset($this->app[RegistryLifeVerifier::class]);
        $this->assertSame('ELIGIBLE', $statuses()[1], 'the verifier is unbound again');

        // A release that is not listed, or a missing approval reference: nothing in scope.
        $this->publishedRelease([], $r1);
        $this->assertSame('NOT_ELIGIBLE', $statuses()[1], 'an unlisted release');
        config(['family_registry_admission.life_policy.living_scope_releases' => ($r1 + 1).':2026-10-25',
            'family_registry_admission.life_policy.approval_reference' => null]);
        $this->assertSame('NOT_ELIGIBLE', $statuses()[1], 'no approval reference');
        $this->assertSame(1, DB::table('family_registry_admissions')->count());

        // The CHECKs that keep a declared life complete.
        $this->assertSame(3, (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid = 'family_registry_admissions'::regclass
            AND conname IN ('chk_registry_admission_life_basis', 'chk_registry_admission_life_scope', 'chk_registry_admission_life_verified')"));
        foreach ([self::CHILD, self::SECOND, $dead, self::HEAD] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $logged));
        }
    }
}
