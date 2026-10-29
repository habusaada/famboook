<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupDisclosure;
use App\Support\CivilLookup\CivilLookupQuota;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\EffectivePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PDO;
use PDOException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\TestCase;

/**
 * FB-CIVIL-02A — the Civil Registry Lookup against PostgreSQL: the real
 * identity_ref_lookup functions (08-lookup.dba.sql) through a real, throwaway
 * reader login, via the HTTP API. Active published release only (presence
 * version, never the highest version_no), normalization, pagination and the
 * breadth cap, links, least privilege, the atomic quota under true concurrency
 * and append-only history. SYNTHETIC data only.
 *
 *   php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/CivilLookup/CivilLookupPostgresTest.php
 */
class CivilLookupPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use FamilyIdentityFixtures;
    use IdentityRefDatabase;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private const BASE = '/api/v1/civil-registry/lookup';

    private User $admin;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['civil_lookup.enabled' => true, 'family_auth.fingerprint' => [
            'key' => 'test-only-civil-lookup-fingerprint-key-0123456789', 'key_version' => 1, 'previous_key' => null, 'previous_key_version' => null,
        ]]);
        $this->setUpIdentityRef();
        DB::unprepared($this->sqlFile('08-lookup.dba.sql'));
        $reader = $this->loginConnection('famboook_identity_reader')->getConfig();
        config(['database.connections.identity_ref_lookup.username' => $reader['username'],
            'database.connections.identity_ref_lookup.password' => $reader['password']]);
        DB::purge('identity_ref_lookup');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMINISTRATOR');
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            parent::tearDown();

            return;
        }
        DB::purge('identity_ref_lookup');
        DB::statement('TRUNCATE civil_lookup_audit_events, civil_lookup_policy_changes, civil_lookup_daily_usage, civil_lookup_user_access, civil_lookup_record_disclosures');
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        // DatabaseTruncation leaves the last test's rows committed (family-side users, persons, families):
        // truncate them so later RefreshDatabase classes start empty.
        $this->truncateTablesForAllConnections();
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
            new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'), $env('DB_PASSWORD'));

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function lookup(string $path, array $body, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->admin)->postJson(self::BASE.$path, $body);
    }

    private function used(User $user): int
    {
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->sum('used');
    }

    /** Opens the record listed by a National ID lookup. @return array<string, mixed> */
    private function open(string $nationalId): array
    {
        $ref = $this->lookup('/search/national-id', ['national_id' => $nationalId])->assertOk()->json('data.results.0.ref');

        return $this->lookup('/records/open', ['ref' => $ref])->assertOk()->json('data.record');
    }

    public function test_only_the_active_release_is_read_with_its_presence_version_not_the_highest(): void
    {
        $r1 = $this->publishedRelease(['900000501' => ['fullname' => 'سعاد خليل اختبار']]);
        $r2 = $this->publishedRelease(['900000501' => ['fullname' => 'سعاد خليل معدل']], $r1);
        // Release 3 reverts the value: its presence names version 1 again, while version 2 exists.
        $r3 = $this->publishedRelease(['900000501' => ['fullname' => 'سعاد خليل اختبار']], $r2);
        $this->assertSame(['highest' => 2, 'active' => 1], array_map('intval', (array) DB::selectOne("SELECT max(v.version_no) AS highest,
                (SELECT pv.version_no FROM identity_ref.release_presence p JOIN identity_ref.record_versions pv ON pv.id = p.record_version_id
                  WHERE p.release_id = ? AND p.record_id = r.id) AS active
            FROM identity_ref.records r JOIN identity_ref.record_versions v ON v.record_id = r.id WHERE r.mainid = '900000501' GROUP BY r.id", [$r3])));

        // A BUILDING release is never visible.
        $run = $this->startRun();
        $this->present($run, $this->newRelease($run, $r3), $this->payload('900000502'));

        $this->lookup('/search/national-id', ['national_id' => '900000501'])->assertOk()
            ->assertJsonPath('data.release_id', $r3)->assertJsonPath('data.results.0.fullname', 'سعاد خليل اختبار');
        $this->lookup('/search/national-id', ['national_id' => '900000502'])->assertOk()->assertJsonPath('data.outcome', 'NOT_FOUND');
        $this->lookup('/search/name', ['fullname' => 'سعاد معدل'])->assertOk()->assertJsonPath('data.outcome', 'NOT_FOUND');
        $record = $this->open('900000501');
        $this->assertStringNotContainsString('relgion', json_encode($record));
        $this->assertSame(4, $this->used($this->admin));
    }

    public function test_name_and_detailed_search_normalize_paginate_and_cap_breadth_in_the_database(): void
    {
        $identities = [
            '900000601' => ['fullname' => 'مُحَمَّد أحمد عبد الله سالم', 'name1' => 'مُحَمَّد', 'father' => 'أحمد', 'grandfath' => 'عبد الله', 'family' => 'سالم'],
            '900000602' => ['fullname' => 'احمد عبدالله سالم', 'name1' => 'احمد', 'father' => 'عبدالله', 'family' => 'سالم'],
            '900000603' => ['fullname' => 'فاطمة احمد سالمة', 'name1' => 'فاطمة', 'father' => 'احمد', 'family' => 'سالمة'],
        ];
        for ($i = 0; $i < 30; $i++) {
            $identities[(string) (910000000 + $i)] = ['fullname' => 'سامي خالد تجريبية', 'name1' => 'سامي', 'father' => 'خالد', 'family' => 'تجريبية'];
        }
        for ($i = 0; $i < 501; $i++) {
            $identities[(string) (920000000 + $i)] = ['fullname' => 'رامي فادي واسع', 'name1' => 'رامي', 'father' => 'فادي', 'family' => 'واسع'];
        }
        $this->publishedRelease($identities);

        // Diacritics, alef forms, extra spaces, any order; compound names with or without a space; ة is NOT merged with ه.
        $this->lookup('/search/name', ['fullname' => ' سالم  مُحَمَّـد   أحمد '])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0601');
        $this->lookup('/search/name', ['fullname' => 'عبد الله سالم'])->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->lookup('/search/name', ['fullname' => 'فاطمه احمد'])->assertOk()->assertJsonPath('data.outcome', 'NOT_FOUND');
        $this->lookup('/search/name', ['fullname' => '%% __'])->assertStatus(422);

        $first = $this->lookup('/search/name', ['fullname' => 'سامي تجريبية'])->assertOk()
            ->assertJsonPath('data.meta.total', 30)->assertJsonCount(25, 'data.results');
        $this->lookup('/search/page', ['ref' => $first->json('data.search_ref'), 'page' => 2])->assertOk()->assertJsonCount(5, 'data.results');

        $this->lookup('/search/parts', ['father' => 'احمد', 'family' => 'سالم'])->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->lookup('/search/parts', ['name1' => 'محمد', 'father' => 'احمد', 'family' => 'سالم'])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0601');

        $this->lookup('/search/name', ['fullname' => 'رامي واسع'])->assertOk()
            ->assertJsonPath('data.outcome', 'TOO_BROAD')->assertJsonCount(0, 'data.results');
        $this->assertSame(7, $this->used($this->admin), 'searches count (including NOT_FOUND and TOO_BROAD); the page and the invalid one do not');
    }

    public function test_links_resolve_existence_and_reverse_links_never_include_the_record_itself(): void
    {
        config(['civil_lookup.reverse_links_enabled' => true]);
        $this->publishedRelease([
            '900000701' => ['fullname' => 'كريم ناصر تجريبي', 'fatherid' => '900000702', 'motherid' => '900000799', 'householdid' => 'H-77'],
            '900000702' => ['fullname' => 'ناصر عادل تجريبي', 'householdid' => 'H-77'],
            '900000703' => ['fullname' => 'ليلى كريم تجريبي', 'fatherid' => '900000701', 'householdid' => 'H-88'],
        ]);
        $record = $this->open('900000701');
        $links = collect($record['links'])->keyBy('relation');
        $this->assertSame(['AVAILABLE', 'NOT_IN_RELEASE', 'EMPTY', 'AVAILABLE', 'AVAILABLE'],
            [$links['FATHER']['status'], $links['MOTHER']['status'], $links['SPOUSE']['status'], $links['HOUSEHOLD']['status'], $links['CHILDREN']['status']]);

        $this->lookup('/links/open', ['ref' => $links['FATHER']['ref']])->assertOk()->assertJsonPath('data.record.national_id', '900000702');
        $household = $this->lookup('/links/list', ['ref' => $links['HOUSEHOLD']['ref'], 'relation' => 'HOUSEHOLD'])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->json('data.results');
        $this->assertSame(['*****0702'], array_column($household, 'national_id'));
        $this->lookup('/links/list', ['ref' => $links['CHILDREN']['ref'], 'relation' => 'CHILDREN'])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0703');
    }

    public function test_the_reader_can_only_execute_the_lookup_functions_and_a_privileged_login_is_refused(): void
    {
        $this->publishedRelease(['900000801' => []]);
        $pdo = DB::connection('identity_ref_lookup')->getPdo();
        $pdo->exec('SET ROLE famboook_identity_reader');
        foreach (['SELECT 1 FROM identity_ref.records', 'SELECT 1 FROM identity_ref.record_versions', 'SELECT 1 FROM identity_ref.active_records',
            'SELECT 1 FROM identity_ref_staging.rows', "SELECT * FROM identity_ref_lookup.list_page('true', '{}', '1', 0, 25, 500)",
            "SELECT identity_ref_lookup.normalize_name('x')", "INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows) VALUES (repeat('a', 64), 't', 1)"] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $pdo->query($sql)), $sql);
        }
        $this->assertSame('900000801', $pdo->query("SELECT mainid FROM identity_ref_lookup.by_mainid('900000801')")->fetchColumn());
        $this->assertSame('23514', $this->sqlStateOf(fn () => $pdo->query("SELECT * FROM identity_ref_lookup.search_fullname(ARRAY['م', 'ح'], 0, 25)")));
        $this->assertSame('23514', $this->sqlStateOf(fn () => $pdo->query("SELECT * FROM identity_ref_lookup.search_fullname(ARRAY['محمد', 'احمد'], 0, 1000)")));

        $config = DB::selectOne("SELECT rolconfig::text AS c, rolcanlogin, rolinherit FROM pg_roles WHERE rolname = 'famboook_identity_lookup_login'");
        $this->assertStringContainsString('default_transaction_read_only=on', $config->c);
        $this->assertStringContainsString('statement_timeout=5s', $config->c);
        $this->assertSame([false, false], [(bool) $config->rolcanlogin, (bool) $config->rolinherit], 'created NOLOGIN and NOINHERIT');

        // A superuser (or writer / owner / monitor) login is refused: nothing is read and nothing charged.
        DB::purge('identity_ref_lookup');
        config(['database.connections.identity_ref_lookup.username' => config('database.connections.pgsql.username'),
            'database.connections.identity_ref_lookup.password' => config('database.connections.pgsql.password')]);
        $this->lookup('/search/national-id', ['national_id' => '900000801'])->assertStatus(503)->assertJsonPath('code', 'LOOKUP_UNAVAILABLE');
        $this->assertSame(0, $this->used($this->admin));
    }

    public function test_concurrent_consumption_never_exceeds_the_limit(): void
    {
        CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->update(['daily_limit' => 1]);
        $user = User::factory()->create();
        $user->assignRole('DATA_ENTRY');
        $policy = new EffectivePolicy('DATA_ENTRY', 1);
        $c = DB::connection()->getConfig();
        $other = pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s'", $c['host'], $c['port'], $c['database'], $c['username'],
            addslashes((string) ($c['password'] ?? ''))), PGSQL_CONNECT_FORCE_NEW);
        $sql = CivilLookupQuota::consumeStatement(true);
        $n = 0;
        $sql = preg_replace_callback('/\?/', function () use (&$n) {
            return '$'.(++$n);
        }, $sql);

        DB::beginTransaction();
        $this->assertSame(1, CivilLookupQuota::consume($user, $policy));
        // The competing request in another session: the same single statement, while the first is uncommitted.
        pg_send_query_params($other, $sql, [$user->id, CivilLookupQuota::today(), now()->toDateTimeString(), 1]);
        usleep(400_000);
        $this->assertTrue(pg_connection_busy($other), 'the competing consume waits for the first transaction');
        DB::commit();
        $result = pg_get_result($other);
        $this->assertSame(0, pg_num_rows($result), 'the guard re-checks the committed count: no second increment');
        pg_close($other);
        $this->assertSame(1, $this->used($user));
        $this->lookup('/search/national-id', ['national_id' => '900000001'], $user)->assertStatus(429)->assertJsonPath('code', 'QUOTA_EXHAUSTED');
    }

    /**
     * FB-CIVIL-02F (ADR §L-6c): the first disclosure of a record is charged once under true
     * concurrency — a second session inserting the same (user, day, release, record) waits for
     * the first transaction and then finds the row; when the first rolls back it proceeds; and
     * disclosures of different records never exceed the limit.
     */
    public function test_concurrent_disclosures_charge_one_record_once_and_never_exceed_the_limit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('DATA_ENTRY');
        $c = DB::connection()->getConfig();
        $other = pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s'", $c['host'], $c['port'], $c['database'], $c['username'],
            addslashes((string) ($c['password'] ?? ''))), PGSQL_CONNECT_FORCE_NEW);
        $insert = 'INSERT INTO civil_lookup_record_disclosures (user_id, disclosure_date, release_id, record_id, created_at)
            VALUES ($1, $2, $3, $4, $5) ON CONFLICT DO NOTHING';
        $mark = fn (int $recordId) => pg_send_query_params($other, $insert, [$user->id, CivilLookupQuota::today(), 1, $recordId, now()->toDateTimeString()]);
        $record = fn (int $id) => new CivilRecord(1, $id, '900000001', []);
        $rows = fn (int $id) => DB::table('civil_lookup_record_disclosures')->where('user_id', $user->id)->where('record_id', $id)->count();

        // The same record, opened twice in parallel: the second waits, then finds the row — it would be free.
        DB::beginTransaction();
        $this->assertSame(1, CivilLookupDisclosure::charge($user, new EffectivePolicy('DATA_ENTRY', 5), $record(77)));
        $mark(77);
        usleep(400_000);
        $this->assertTrue(pg_connection_busy($other), 'the competing insert waits for the first transaction');
        DB::commit();
        $this->assertSame(0, pg_affected_rows(pg_get_result($other)), 'the record is already disclosed: no second charge');
        $this->assertSame([1, 1], [$this->used($user), $rows(77)]);

        // When the first transaction rolls back (e.g. its consume was refused), the waiting one proceeds.
        DB::beginTransaction();
        CivilLookupDisclosure::charge($user, new EffectivePolicy('DATA_ENTRY', 5), $record(78));
        $mark(78);
        usleep(400_000);
        $this->assertTrue(pg_connection_busy($other));
        DB::rollBack();
        $this->assertSame(1, pg_affected_rows(pg_get_result($other)), 'nothing was disclosed by the rolled-back request');
        $this->assertSame(1, $this->used($user));

        // Different records in parallel at the limit: the second session's consume waits, the guard refuses it,
        // and its transaction (insert + consume, as CivilLookupDisclosure::charge) rolls back its row.
        $limit = $this->used($user) + 1;
        $consume = preg_replace_callback('/\?/', function () {
            static $n = 0;

            return '$'.(++$n);
        }, CivilLookupQuota::consumeStatement(true));
        DB::beginTransaction();
        $this->assertSame($limit, CivilLookupDisclosure::charge($user, new EffectivePolicy('DATA_ENTRY', $limit), $record(80)));
        pg_query($other, 'BEGIN');
        pg_query_params($other, $insert, [$user->id, CivilLookupQuota::today(), 1, 81, now()->toDateTimeString()]);
        pg_send_query_params($other, $consume, [$user->id, CivilLookupQuota::today(), now()->toDateTimeString(), $limit]);
        usleep(400_000);
        $this->assertTrue(pg_connection_busy($other), 'the competing consume waits on the day counter');
        DB::commit();
        $this->assertSame(0, pg_num_rows(pg_get_result($other)), 'the guard re-checks the committed count: refused');
        pg_query($other, 'ROLLBACK');
        pg_close($other);
        $this->assertSame([$limit, 1, 0], [$this->used($user), $rows(80), $rows(81)]);
    }

    /** FB-CIVIL-02G: a reveal against the real lookup functions, audited as ID_REVEALED under the PostgreSQL CHECK. */
    public function test_a_reveal_returns_one_id_and_its_audit_event_passes_the_database_check(): void
    {
        $this->publishedRelease(['900000001' => ['fullname' => 'سامي خالد اختبار']]);
        $user = User::factory()->create();
        $user->assignRole('DATA_ENTRY');
        $list = $this->lookup('/search/national-id', ['national_id' => '900000001'], $user)->assertOk()
            ->assertJsonPath('data.results.0.national_id', '*****0001');
        $this->lookup('/identifiers/reveal', ['ref' => $list->json('data.results.0.ref')], $user)->assertOk()
            ->assertJsonPath('data.national_id', '900000001');
        $this->assertSame(1, $this->used($user), 'the exact-ID search only: its record is revealed free');
        $this->assertSame(['event_type' => 'ID_REVEALED', 'outcome_code' => 'FOUND'], (array) DB::selectOne(
            "SELECT event_type, outcome_code FROM civil_lookup_audit_events WHERE event_type = 'ID_REVEALED'"));
        $this->assertThrows(fn () => DB::table('civil_lookup_audit_events')->insert(['event_type' => 'NOT_AN_EVENT', 'actor_user_id' => $user->id,
            'actor_side' => 'STAFF', 'outcome_code' => 'FOUND', 'lookup_context' => 'STAFF']), QueryException::class);
    }

    /** FB-CIVIL-02F: the disclosure table's UNIQUE key on PostgreSQL, and its migration rolls back cleanly. */
    public function test_the_disclosure_migration_has_its_unique_key_and_rolls_back(): void
    {
        $user = User::factory()->create();
        $row = ['user_id' => $user->id, 'disclosure_date' => CivilLookupQuota::today(), 'release_id' => 1, 'record_id' => 5, 'created_at' => now()];
        DB::table('civil_lookup_record_disclosures')->insert($row);
        $this->assertThrows(fn () => DB::table('civil_lookup_record_disclosures')->insert($row), UniqueConstraintViolationException::class);
        DB::table('civil_lookup_record_disclosures')->insert(['disclosure_date' => CivilLookupQuota::now()->addDay()->toDateString()] + $row);
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uq_civil_lookup_record_disclosure'");
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $index->indexdef);
        $this->assertStringContainsString('(user_id, disclosure_date, release_id, record_id)', $index->indexdef);
        $this->assertSame(['created_at', 'disclosure_date', 'id', 'record_id', 'release_id', 'user_id'],
            collect(Schema::getColumnListing('civil_lookup_record_disclosures'))->sort()->values()->all(), 'no National ID column');

        $migration = require database_path('migrations/2026_10_31_090000_create_civil_lookup_record_disclosures.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('civil_lookup_record_disclosures'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('civil_lookup_record_disclosures'));
    }

    public function test_history_is_append_only_even_for_the_database_owner_and_a_missing_release_is_not_charged(): void
    {
        $this->lookup('/search/national-id', ['national_id' => '900000901'])->assertStatus(503)->assertJsonPath('code', 'NO_PUBLISHED_RELEASE');
        $this->assertSame(0, $this->used($this->admin));

        $this->publishedRelease(['900000901' => []]);
        $this->lookup('/search/national-id', ['national_id' => '900000901'])->assertOk();
        $super = User::factory()->create();
        $super->assignRole('SUPER_ADMIN');
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => 10])->assertOk();
        foreach (['civil_lookup_audit_events' => 'outcome_code', 'civil_lookup_policy_changes' => 'new_daily_limit'] as $table => $column) {
            $this->assertSame('23001', $this->sqlStateOf(fn () => DB::update("UPDATE {$table} SET {$column} = NULL")), $table);
            $this->assertSame('23001', $this->sqlStateOf(fn () => DB::delete("DELETE FROM {$table}")), $table);
        }
        $this->assertSame('23514', $this->sqlStateOf(fn () => DB::update("UPDATE civil_lookup_role_policies SET daily_limit = 5 WHERE role_name = 'SUPER_ADMIN'")));
        // FB-CIVIL-02C (corrected): ADMINISTRATOR is automatic and unlimited too; no other role may be unlimited.
        foreach (['daily_limit = 200, unlimited_allowed = false', 'enabled = false'] as $set) {
            foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
                $this->assertSame('23514', $this->sqlStateOf(fn () => DB::update("UPDATE civil_lookup_role_policies SET {$set} WHERE role_name = '{$role}'")), "{$role}: {$set}");
            }
        }
        $this->assertSame('23514', $this->sqlStateOf(fn () => DB::update("UPDATE civil_lookup_role_policies SET daily_limit = NULL, unlimited_allowed = true WHERE role_name = 'DATA_ENTRY'")));
    }

    /**
     * FB-CIVIL-02B: a household head's lookup through the family mount and the real functions
     * (EXTENDED_CONTROLLED, shared counter, FAMILY context recorded), and the database CHECKs on the
     * per-side grant and the audit context.
     */
    public function test_a_granted_household_head_reads_the_extended_profile_and_the_family_side_rows_are_constrained(): void
    {
        config(['civil_lookup.family_side_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        CivilLookupRolePolicy::where('role_name', 'FAMILY_USER')->update(['enabled' => true]);
        $this->useFamilyAuthKey();
        $head = $this->activatedHead('123456780')['user'];
        CivilLookupUserAccess::create(['user_id' => $head->id, 'access' => 'GRANTED', 'sides' => 'FAMILY', 'changed_by' => $head->id, 'changed_at' => now()]);
        $this->publishedRelease(['900000601' => ['fullname' => 'سارة عادل اختبار', 'phonenum' => '0599111111', 'worker' => '1']]);

        $ref = $this->actingAs($head)->postJson('/api/v1/family/civil-registry/lookup/search/national-id', ['national_id' => '900000601'])
            ->assertOk()->json('data.results.0.ref');
        $record = $this->actingAs($head)->postJson('/api/v1/family/civil-registry/lookup/records/open', ['ref' => $ref])->assertOk()
            ->assertJsonPath('data.record.level', 'EXTENDED_CONTROLLED')->json('data.record');
        foreach (['relgion', 'phonenum', '0599111111', 'worker', '900000601'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($record, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(1, $this->used($head));
        $this->assertSame(['FAMILY'], DB::table('civil_lookup_audit_events')->where('actor_user_id', $head->id)->distinct()->pluck('lookup_context')->all());

        // GRANTED always names its sides; REVOKED never does; a family-side context only for a family-side actor.
        $other = User::factory()->create();
        $insert = fn (array $row) => DB::table('civil_lookup_user_access')->insert($row + ['user_id' => $other->id, 'changed_by' => $other->id, 'changed_at' => now()]);
        $this->assertSame('23514', $this->sqlStateOf(fn () => $insert(['access' => 'GRANTED', 'sides' => null])));
        $this->assertSame('23514', $this->sqlStateOf(fn () => $insert(['access' => 'GRANTED', 'sides' => 'COORDINATOR,FAMILY'])));
        $this->assertSame('23514', $this->sqlStateOf(fn () => $insert(['access' => 'REVOKED', 'sides' => 'FAMILY'])));
        $this->assertSame('23514', $this->sqlStateOf(fn () => DB::table('civil_lookup_audit_events')->insert(['event_type' => 'SEARCH_BY_ID',
            'actor_user_id' => $this->admin->id, 'actor_side' => 'STAFF', 'outcome_code' => 'FOUND', 'lookup_context' => 'FAMILY', 'occurred_at' => now()])));
        $this->assertSame('23001', $this->sqlStateOf(fn () => DB::update("UPDATE civil_lookup_audit_events SET lookup_context = 'STAFF'")));
    }
}
