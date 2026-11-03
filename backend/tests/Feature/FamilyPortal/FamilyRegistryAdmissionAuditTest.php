<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\FingerprintContext;
use App\Enums\RegistryChildReason;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-1: the admission audit helpers and the audit integrity
 * migration, on both drivers (the CHECKs and triggers on PostgreSQL only). Nothing
 * here admits anyone: no action, route or source exists. Synthetic data only.
 */
class FamilyRegistryAdmissionAuditTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_11_02_100000_harden_family_registry_admission_audit.php';

    private const CHILD = '401234567';

    private User $actor;

    private Family $family;

    private FamilyMembership $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->actor = User::factory()->familySide()->create();
        $this->head = FamilyMembership::factory()->householdHead()->create();
        $this->family = $this->head->family;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return DB::table('family_registry_admission_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The CHECKs and triggers exist on PostgreSQL only.');
        }
    }

    /** The statement fails, inside a savepoint so the test transaction survives. */
    private function assertRefused(callable $statement, string $label, ?string $because = null): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Accepted: {$label}");
        } catch (QueryException $e) {
            if ($because !== null) {
                $this->assertStringContainsString($because, $e->getMessage(), $label);
            }
            $this->addToAssertionCount(1);
        }
    }

    private function row(array $override = []): array
    {
        return [
            'event_type' => 'ADMISSION_REFUSED', 'actor_user_id' => $this->actor->id, 'family_id' => $this->family->id,
            'outcome_code' => 'REFERENCE_INVALID', 'release_id' => null, 'reference_fingerprint' => null, 'reference_key_version' => null,
            'result_count' => null, 'ip_hash' => null, 'created_at' => now(), ...$override,
        ];
    }

    // ---------------------------------------------------------------- the helpers

    public function test_a_success_commits_and_rolls_back_with_its_transaction(): void
    {
        try {
            DB::transaction(function () {
                Audit::admissionSucceeded($this->actor, $this->family, 3, Audit::PERSON_CREATED, self::CHILD, '127.0.0.1');
                throw new RuntimeException('The admission failed after the event.');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->rows());

        DB::transaction(fn () => Audit::admissionSucceeded($this->actor, $this->family, 3, Audit::PERSON_ATTACHED, self::CHILD, '127.0.0.1'));
        $row = $this->rows()[0];
        $reference = Audit::referenceFingerprint(self::CHILD);
        $this->assertSame(['ADMISSION_SUCCEEDED', 'PERSON_ATTACHED', 3, $reference['fingerprint'], 1, null],
            [$row['event_type'], $row['outcome_code'], (int) $row['release_id'], $row['reference_fingerprint'], (int) $row['reference_key_version'], $row['result_count']]);
        $this->assertSame([(int) $this->actor->id, (int) $this->family->id], [(int) $row['actor_user_id'], (int) $row['family_id']]);
    }

    public function test_replayed_and_refused_events(): void
    {
        Audit::admissionReplayed($this->actor, $this->family, 4, self::CHILD, null);
        Audit::admissionRefused($this->actor, $this->family, RegistryChildReason::ACTIVE_MEMBERSHIP_ELSEWHERE->value, 4, self::CHILD, '10.0.0.1');
        Audit::admissionRefused($this->actor, $this->family, Audit::REFERENCE_INVALID, null, null, '10.0.0.1');

        $this->assertSame([
            ['ADMISSION_REPLAYED', 'REPLAYED', 4, true, false],
            ['ADMISSION_REFUSED', 'ACTIVE_MEMBERSHIP_ELSEWHERE', 4, true, true],
            ['ADMISSION_REFUSED', 'REFERENCE_INVALID', null, false, true],
        ], array_map(fn (array $r) => [$r['event_type'], $r['outcome_code'], $r['release_id'] === null ? null : (int) $r['release_id'],
            $r['reference_fingerprint'] !== null, $r['ip_hash'] !== null], $this->rows()));
    }

    public function test_arguments_are_checked_before_anything_is_written(): void
    {
        $calls = [
            'outcome' => fn () => DB::transaction(fn () => Audit::admissionSucceeded($this->actor, $this->family, 3, Audit::REPLAYED, self::CHILD, null)),
            'refusal LISTED' => fn () => Audit::admissionRefused($this->actor, $this->family, Audit::LISTED, 3, self::CHILD, null),
            'refusal PERSON_CREATED' => fn () => Audit::admissionRefused($this->actor, $this->family, Audit::PERSON_CREATED, 3, self::CHILD, null),
            'refusal unknown' => fn () => Audit::admissionRefused($this->actor, $this->family, 'SOMETHING_ELSE', 3, self::CHILD, null),
            'release 0' => fn () => Audit::admissionReplayed($this->actor, $this->family, 0, self::CHILD, null),
            'release -1' => fn () => Audit::admissionRefused($this->actor, $this->family, Audit::LOST_RACE, -1, self::CHILD, null),
        ];
        foreach (['', '40123456', '4012345678', '000000000', '٤٠١٢٣٤٥٦٧', ' 401234567', '401-234-567'] as $id) {
            $calls["id {$id}"] = fn () => Audit::admissionReplayed($this->actor, $this->family, 3, $id, null);
        }
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("Accepted: {$label}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('40123456', $e->getMessage(), $label);
            }
        }
        $this->assertSame([], $this->rows());
    }

    public function test_every_refusal_code_fits_the_outcome_format(): void
    {
        foreach (Audit::refusalCodes() as $code) {
            $this->assertMatchesRegularExpression('/\A[A-Z][A-Z0-9_]{1,63}\z/', $code);
            Audit::admissionRefused($this->actor, $this->family, $code, 2, self::CHILD, null);
        }
        $this->assertNotContains(Audit::LISTED, Audit::refusalCodes());
        $this->assertNotContains(Audit::REPLAYED, Audit::refusalCodes());
        $this->assertCount(count(Audit::refusalCodes()), $this->rows());
    }

    public function test_rows_carry_no_identifier_name_ip_or_token(): void
    {
        DB::transaction(fn () => Audit::admissionSucceeded($this->actor, $this->family, 3, Audit::PERSON_CREATED, self::CHILD, '192.168.10.20'));
        Audit::admissionRefused($this->actor, $this->family, RegistryChildReason::DECEASED->value, 3, self::CHILD, '192.168.10.20');
        $dump = json_encode($this->rows(), JSON_UNESCAPED_UNICODE);

        foreach ([self::CHILD, '1234567', '192.168.10.20', $this->actor->name, 'national', 'name', 'payload', 'token'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, (string) $dump, (string) $secret);
        }
    }

    public function test_the_reference_fingerprint_is_keyed_and_domain_separated(): void
    {
        $reference = Audit::referenceFingerprint(self::CHILD);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $reference['fingerprint']);
        $this->assertSame($reference, Audit::referenceFingerprint(self::CHILD));
        $this->assertNotSame($reference['fingerprint'], Audit::referenceFingerprint('401234568')['fingerprint']);
        foreach ([FingerprintContext::FAMILY_REGISTRY_ADMISSION_IP, FingerprintContext::LOGIN_ID, FingerprintContext::CIVIL_LOOKUP_QUERY] as $other) {
            $this->assertNotSame($reference['fingerprint'], KeyedFingerprint::of($other, self::CHILD), $other->name);
        }
        $this->assertNotSame($reference['fingerprint'], hash('sha256', self::CHILD));
    }

    public function test_a_failed_insert_throws(): void
    {
        DB::statement('DROP TABLE family_registry_admission_events');

        $this->expectException(QueryException::class);
        Audit::admissionRefused($this->actor, $this->family, Audit::LOST_RACE, 3, self::CHILD, null);
    }

    // ---------------------------------------------------------------- PostgreSQL integrity

    public function test_the_admission_event_checks(): void
    {
        $this->pgsql();
        $fp = ['reference_fingerprint' => str_repeat('a', 64), 'reference_key_version' => 1];
        $refused = [
            'success without a child' => ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'PERSON_CREATED', 'release_id' => 3],
            'success without a release' => ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'PERSON_CREATED', ...$fp],
            'replay without a child' => ['event_type' => 'ADMISSION_REPLAYED', 'outcome_code' => 'REPLAYED', 'release_id' => 3],
            'replay without a release' => ['event_type' => 'ADMISSION_REPLAYED', 'outcome_code' => 'REPLAYED', ...$fp],
            'success outcome REPLAYED' => ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'REPLAYED', 'release_id' => 3, ...$fp],
            'success outcome LOST_RACE' => ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'LOST_RACE', 'release_id' => 3, ...$fp],
            'replay outcome PERSON_CREATED' => ['event_type' => 'ADMISSION_REPLAYED', 'outcome_code' => 'PERSON_CREATED', 'release_id' => 3, ...$fp],
            'refusal outcome PERSON_ATTACHED' => ['outcome_code' => 'PERSON_ATTACHED'],
            'refusal outcome REPLAYED' => ['outcome_code' => 'REPLAYED'],
            'refusal outcome LISTED' => ['outcome_code' => 'LISTED'],
            'release 0' => ['release_id' => 0],
            'success with a count' => ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'PERSON_CREATED', 'release_id' => 3, 'result_count' => 1, ...$fp],
        ];
        foreach ($refused as $label => $override) {
            $this->assertRefused(fn () => DB::table('family_registry_admission_events')->insert($this->row($override)), $label);
        }

        // Still accepted: a refusal before the reference was read, and discovery rows as before.
        DB::table('family_registry_admission_events')->insert($this->row());
        DB::table('family_registry_admission_events')->insert($this->row(['outcome_code' => 'NOT_A_CHILD_OF_HEAD', 'release_id' => 3, ...$fp]));
        Audit::discoveryListed($this->actor, $this->family, null, 0, false, null);
        Audit::discoveryListed($this->actor, $this->family, 3, 0, true, null);
        $this->assertCount(4, $this->rows());
    }

    private const TABLES = ['family_registry_admission_events', 'family_registry_admissions'];

    /** One committed-shape row in each table (inside the test transaction). */
    private function history(): void
    {
        Audit::admissionRefused($this->actor, $this->family, Audit::LOST_RACE, 3, self::CHILD, null);
        $member = FamilyMembership::factory()->create(['family_id' => $this->family->id]);
        DB::table('family_registry_admissions')->insert([
            'uuid' => (string) Str::uuid(), 'family_id' => $this->family->id, 'person_id' => $member->person_id,
            'family_membership_id' => $member->id, 'admitted_by' => $this->actor->id,
            'admitted_by_person_id' => $this->head->person_id,
            'client_reference' => (string) Str::uuid(), 'outcome_type' => 'PERSON_ATTACHED', 'parent_link' => 'FATHER',
            'reference_fingerprint' => Audit::referenceFingerprint(self::CHILD)['fingerprint'], 'reference_key_version' => 1,
            'release_id' => 3, 'declaration_version' => '2026-11-v1', 'declared_at' => now(), 'created_at' => now(),
        ]);
    }

    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), self::TABLES);
    }

    /** A role that is NOT a superuser or the owner, with more rights than an application role should have. */
    private function probeRole(array $privileges = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE']): string
    {
        $role = 'famboook_audit_probe_'.Str::lower(Str::random(8));
        DB::statement("CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT");
        foreach (self::TABLES as $table) {
            DB::statement('GRANT '.implode(', ', $privileges)." ON {$table} TO {$role}");
            if (in_array('INSERT', $privileges, true)) {
                DB::statement("GRANT USAGE ON SEQUENCE {$table}_id_seq TO {$role}");
            }
        }

        return $role;
    }

    public function test_truncate_is_refused_while_a_table_holds_history(): void
    {
        $this->pgsql();
        $this->history();
        foreach ([...self::TABLES, 'family_registry_admission_events, family_registry_admissions'] as $tables) {
            foreach (['', ' CASCADE', ' RESTART IDENTITY CASCADE'] as $suffix) {
                $this->assertRefused(fn () => DB::statement("TRUNCATE {$tables}{$suffix}"), "TRUNCATE {$tables}{$suffix}", 'append-only: TRUNCATE is not allowed');
            }
        }
        // The harness path: TRUNCATE users CASCADE reaches both tables and is refused as a whole.
        $this->assertRefused(fn () => DB::statement('TRUNCATE users RESTART IDENTITY CASCADE'), 'TRUNCATE users CASCADE', 'append-only: TRUNCATE is not allowed');
        $this->assertRefused(fn () => DB::table('family_registry_admission_events')->update(['outcome_code' => 'CHANGED']), 'UPDATE events');
        $this->assertRefused(fn () => DB::table('family_registry_admission_events')->delete(), 'DELETE events');
        $this->assertRefused(fn () => DB::table('family_registry_admissions')->update(['declaration_version' => 'x']), 'UPDATE evidence');
        $this->assertRefused(fn () => DB::table('family_registry_admissions')->delete(), 'DELETE evidence');
        $this->assertSame([1, 1], $this->counts());
    }

    public function test_a_test_database_name_or_a_session_setting_opens_nothing(): void
    {
        $this->pgsql();
        $this->assertStringEndsWith('_test', (string) DB::scalar('SELECT current_database()'));
        $this->history();
        foreach (["SET LOCAL famboook.enforce_truncate_guard = 'off'", "SET LOCAL famboook.enforce_truncate_guard = 'on'",
            "SET LOCAL famboook.allow_truncate = 'on'", "SET LOCAL application_name = 'famboook_test_harness'"] as $setting) {
            $this->assertRefused(function () use ($setting) {
                DB::statement($setting);
                DB::statement('TRUNCATE family_registry_admission_events CASCADE');
            }, $setting, 'append-only: TRUNCATE is not allowed');
        }
        $source = (string) DB::scalar("SELECT prosrc FROM pg_proc WHERE proname = 'famboook_family_registry_admission_no_truncate'");
        foreach (['current_database', 'current_user', 'session_user', 'enforce_truncate_guard', '_test', 'pg_has_role'] as $absent) {
            $this->assertStringNotContainsString($absent, $source, $absent);
        }
        $this->assertFalse((bool) DB::scalar("SELECT prosecdef FROM pg_proc WHERE proname = 'famboook_family_registry_admission_no_truncate'"), 'SECURITY DEFINER');
        $this->assertSame([1, 1], $this->counts());
    }

    public function test_a_non_owner_role_cannot_truncate_or_switch_the_guard_off(): void
    {
        $this->pgsql();
        $this->history();
        $role = $this->probeRole();
        // Positive control: the role really is in effect, is no superuser, and may read and insert.
        DB::transaction(function () use ($role) {
            DB::statement("SET LOCAL ROLE {$role}");
            $this->assertSame([$role, false], [DB::scalar('SELECT current_user'), (bool) DB::scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')]);
            $this->assertSame(1, (int) DB::scalar('SELECT count(*) FROM family_registry_admission_events'));
            Audit::admissionRefused($this->actor, $this->family, Audit::LOST_RACE, 3, self::CHILD, null);
            // A committed savepoint keeps SET LOCAL until the test transaction ends.
            DB::statement('RESET ROLE');
        });
        $this->assertSame(DB::scalar('SELECT session_user'), DB::scalar('SELECT current_user'));
        $as = fn (string $sql) => function () use ($role, $sql) {
            DB::statement("SET LOCAL ROLE {$role}");
            DB::statement($sql);
        };
        foreach ([
            "UPDATE family_registry_admission_events SET outcome_code = 'CHANGED'", 'DELETE FROM family_registry_admissions',
            "SET session_replication_role = 'replica'",
            'ALTER TABLE family_registry_admission_events DISABLE TRIGGER ALL',
            'ALTER TABLE family_registry_admission_events DISABLE TRIGGER trg_family_registry_admission_events_no_truncate',
            'DROP TRIGGER trg_family_registry_admissions_no_truncate ON family_registry_admissions',
            'DROP FUNCTION famboook_family_registry_admission_no_truncate() CASCADE',
            'ALTER TABLE family_registry_admission_events DROP CONSTRAINT chk_registry_admission_event_admission_reference',
        ] as $sql) {
            $this->assertRefused($as($sql), "{$role}: {$sql}");
        }

        foreach (['TRUNCATE family_registry_admission_events', 'TRUNCATE family_registry_admissions CASCADE'] as $sql) {
            $this->assertRefused($as($sql), "{$role}: {$sql}", 'append-only: TRUNCATE is not allowed');
        }

        // A role holding TRUNCATE but not SELECT cannot let the guard read the table, so it is refused too.
        $blind = $this->probeRole(['TRUNCATE']);
        $this->assertRefused(function () use ($blind) {
            DB::statement("SET LOCAL ROLE {$blind}");
            DB::statement('TRUNCATE family_registry_admission_events');
        }, "{$blind}: TRUNCATE without SELECT");
        $this->assertSame([2, 1], $this->counts());
    }

    /**
     * ADD-WP-07B-1R2: the hijack confirmed in review. A non-owner role with only SELECT and
     * TRUNCATE on the tables, its own schema and TEMP, replaces current_setting(), format()
     * and <> and puts its schema first: the guard must still see the rows. Everything is
     * created inside the test transaction (or a savepoint) and rolled back with it.
     */
    public function test_a_hostile_search_path_cannot_influence_the_guard(): void
    {
        $this->pgsql();
        $this->history();
        $role = $this->probeRole(['SELECT', 'TRUNCATE']);
        $schema = $role.'_schema';
        DB::statement("CREATE SCHEMA {$schema} AUTHORIZATION {$role}");

        $hostile = function (string $searchPath, string $truncate, bool $temporary) use ($role, $schema) {
            return function () use ($role, $schema, $searchPath, $truncate, $temporary) {
                DB::statement("SET LOCAL ROLE {$role}");
                $this->assertSame($role, DB::scalar('SELECT current_user'));
                DB::unprepared(<<<SQL
                    CREATE FUNCTION {$schema}.current_setting(text) RETURNS text LANGUAGE sql AS \$f\$ SELECT 'read committed'::text \$f\$;
                    CREATE FUNCTION {$schema}.format(text, regclass) RETURNS text LANGUAGE sql AS \$f\$ SELECT 'SELECT false'::text \$f\$;
                    CREATE FUNCTION {$schema}.never_differs(text, text) RETURNS boolean LANGUAGE sql AS \$f\$ SELECT false \$f\$;
                    CREATE OPERATOR {$schema}.<> (LEFTARG = text, RIGHTARG = text, FUNCTION = {$schema}.never_differs);
                SQL);
                if ($temporary) {
                    // Temporary objects: an empty look-alike table and a temporary format().
                    DB::unprepared(<<<'SQL'
                        CREATE TEMPORARY TABLE family_registry_admission_events (id bigint);
                        CREATE TEMPORARY TABLE family_registry_admissions (id bigint);
                        CREATE FUNCTION pg_temp.format(text, regclass) RETURNS text LANGUAGE sql AS $f$ SELECT 'SELECT false'::text $f$;
                    SQL);
                }
                DB::statement("SET LOCAL search_path = {$searchPath}");
                // The hostile objects are really in effect for this session.
                $this->assertSame('read committed', DB::scalar("SELECT current_setting('anything')"));
                DB::statement($truncate);
            };
        };

        foreach ([
            "{$schema}, pg_catalog, public" => false,
            "pg_temp, {$schema}, pg_catalog, public" => true,
        ] as $searchPath => $temporary) {
            foreach (['TRUNCATE public.family_registry_admission_events', 'TRUNCATE public.family_registry_admissions CASCADE',
                'TRUNCATE public.family_registry_admission_events, public.family_registry_admissions CASCADE'] as $truncate) {
                $this->assertRefused($hostile($searchPath, $truncate, $temporary), "{$searchPath}: {$truncate}", 'append-only: TRUNCATE is not allowed');
            }
        }

        $this->assertSame(DB::scalar('SELECT session_user'), DB::scalar('SELECT current_user'));
        $this->assertSame([1, 1], $this->counts());
    }

    public function test_the_guard_function_pins_its_search_path_and_qualifies_built_ins(): void
    {
        $this->pgsql();
        $function = DB::selectOne("SELECT p.prosecdef, p.proconfig, p.prosrc, n.nspname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.proname = 'famboook_family_registry_admission_no_truncate'");

        $this->assertFalse((bool) $function->prosecdef, 'SECURITY DEFINER');
        $this->assertSame('public', $function->nspname);
        $this->assertSame('{"search_path=pg_catalog, pg_temp"}', $function->proconfig);
        foreach (['pg_catalog.current_setting(', 'pg_catalog.format(', 'OPERATOR(pg_catalog.<>)', '::pg_catalog.regclass'] as $qualified) {
            $this->assertStringContainsString($qualified, $function->prosrc, $qualified);
        }
        foreach (['current_database', 'session_user', 'current_user', 'pg_has_role', 'enforce_truncate_guard', '_test'] as $absent) {
            $this->assertStringNotContainsString($absent, $function->prosrc, $absent);
        }
        // Both tables fire this very function, by OID.
        $this->assertSame(2, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE p.proname = 'famboook_family_registry_admission_no_truncate' AND t.tgname LIKE 'trg_family_registry_admission%_no_truncate'"));
    }

    public function test_an_empty_table_may_be_truncated_so_the_test_harness_still_works(): void
    {
        $this->pgsql();
        $this->assertSame([0, 0], $this->counts());
        DB::transaction(fn () => DB::statement('TRUNCATE family_registry_admission_events, family_registry_admissions RESTART IDENTITY CASCADE'));
        // What DatabaseTruncation issues for every table: users cascades into both, which are empty.
        DB::transaction(fn () => DB::statement('TRUNCATE users RESTART IDENTITY CASCADE'));
        $this->addToAssertionCount(1);
    }

    public function test_only_read_committed_may_truncate_even_an_empty_table(): void
    {
        $this->pgsql();
        // A separate session: the guard must not trust an old snapshot that could miss committed rows.
        config(['database.connections.pgsql_audit_probe' => config('database.connections.pgsql')]);
        $probe = DB::connection('pgsql_audit_probe');
        $probe->statement("SET lock_timeout = '5s'");
        try {
            foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $level) {
                $probe->beginTransaction();
                try {
                    $probe->statement("SET TRANSACTION ISOLATION LEVEL {$level}");
                    $probe->statement('TRUNCATE family_registry_admission_events');
                    $this->fail("TRUNCATE ran under {$level}.");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('READ COMMITTED', $e->getMessage(), $level);
                } finally {
                    $probe->rollBack();
                }
            }
            $probe->beginTransaction();
            $probe->statement('TRUNCATE family_registry_admission_events');
            $probe->rollBack();
        } finally {
            DB::purge('pgsql_audit_probe');
        }
    }

    public function test_the_migration_rolls_back_when_empty_and_refuses_with_admission_history(): void
    {
        $this->pgsql();
        $migration = require self::MIGRATION;
        $constraints = fn () => (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conname LIKE 'chk_registry_admission_event_%'");
        $triggers = fn () => (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'trg_family_registry_admission%_no_truncate'");
        $this->assertSame([11, 2], [$constraints(), $triggers()]);

        Audit::discoveryListed($this->actor, $this->family, 3, 1, false, null);
        DB::transaction(fn () => $migration->down());
        $this->assertSame([6, 0], [$constraints(), $triggers()]);
        DB::transaction(fn () => $migration->up());
        $this->assertSame([11, 2], [$constraints(), $triggers()]);

        Audit::admissionRefused($this->actor, $this->family, Audit::LOST_RACE, 3, self::CHILD, null);
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('The guards were removed with admission history.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('admission history exists', $e->getMessage());
        }
        $this->assertSame([11, 2], [$constraints(), $triggers()]);
    }
}
