<?php

namespace Tests\Feature\IdentityRef;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P1 — role separation (P1 test list items 10, 11) and the
 * SECURITY DEFINER hardening. Every statement runs under the real PostgreSQL
 * role (SET ROLE), so each 42501 below is PostgreSQL's own privilege check.
 */
class IdentityRefRolesPostgresTest extends TestCase
{
    use IdentityRefDatabase;

    private const TABLES = ['import_runs', 'import_files', 'import_issues', 'records', 'record_versions',
        'dataset_releases', 'release_presence', 'active_release', 'active_records'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef();
    }

    protected function tearDown(): void
    {
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    /** 10 — the application role (and the reserved reader) can neither read nor write. */
    public function test_application_and_reader_roles_have_no_access_at_all(): void
    {
        $this->publishedRelease(['900000030' => []]);
        $run = $this->asRole(self::OWNER, fn () => DB::selectOne('SELECT id FROM identity_ref.import_runs LIMIT 1')->id);

        $statements = [
            'SELECT count(*) FROM identity_ref.records',
            'SELECT count(*) FROM identity_ref.record_versions',
            'SELECT count(*) FROM identity_ref.active_records',
            'SELECT count(*) FROM identity_ref.active_release',
            "INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES ('900000031', '{$run}')",
            "INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows) VALUES ('".str_repeat('a', 64)."', 't', 1)",
            "UPDATE identity_ref.import_runs SET error_code = 'X'",
            'DELETE FROM identity_ref.records',
            'TRUNCATE identity_ref.release_presence',
            'SELECT identity_ref.publish_release(1)',
            'SELECT identity_ref.abandon_release(1)',
            'SELECT identity_ref.carry_forward_missing(1)',
            'CREATE TABLE identity_ref.intruder (id int)',
        ];
        foreach ([self::APP, self::READER] as $role) {
            foreach ($statements as $sql) {
                $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole($role, fn () => DB::unprepared($sql))), "{$role}: {$sql}");
            }
            $this->assertFalse((bool) DB::selectOne("SELECT has_schema_privilege(?, 'identity_ref', 'USAGE') AS p", [$role])->p);
            foreach (self::TABLES as $table) {
                $this->assertFalse((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS p',
                    [$role, 'identity_ref.'.$table, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER'])->p, "{$role} on {$table}");
            }
        }
        $this->assertSame(['900000030' => 'PRESENT'], $this->activeIdentities());
    }

    /** 11 — the writer can perform the full import path, and nothing else. */
    public function test_writer_can_record_a_run_and_publish_through_the_controlled_path(): void
    {
        $run = $this->startRun();
        $this->asRole(self::WRITER, function () use ($run) {
            DB::insert("INSERT INTO identity_ref.import_files (run_id, file_name, expected_sha256, expected_rows) VALUES (?, 'part-001.csv', ?, 2)",
                [$run, str_repeat('c', 64)]);
            DB::update("UPDATE identity_ref.import_files SET actual_sha256 = ?, loaded_rows = 2, status = 'STAGED' WHERE run_id = ?",
                [str_repeat('c', 64), $run]);
            DB::insert("INSERT INTO identity_ref.import_issues (run_id, file_name, line_number, code, column_name, key_fingerprint)
                        VALUES (?, 'part-001.csv', 7, 'UNKNOWN_CODE', 'govern', decode(?, 'hex'))", [$run, str_repeat('ab', 32)]);
        });
        $release = $this->newRelease($run, null);
        $this->present($run, $release, $this->payload('900000040'));
        $this->present($run, $release, $this->payload('900000041'));
        $this->assertSame(2, $this->publish($release));
        $this->asRole(self::WRITER, fn () => DB::update("UPDATE identity_ref.import_runs SET status = 'SUCCEEDED', finished_at = now(),
            rows_loaded = 2, new_records = 2, new_versions = 2, issues = 1 WHERE id = ?", [$run]));

        $this->assertSame(['900000040' => 'PRESENT', '900000041' => 'PRESENT'], $this->activeIdentities());
        // A finished run is frozen, even for the writer's granted columns.
        $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::update(
            'UPDATE identity_ref.import_runs SET rows_loaded = 9 WHERE id = ?', [$run]))));
    }

    public function test_writer_cannot_bypass_the_controlled_path_or_escalate(): void
    {
        $release = $this->publishedRelease(['900000050' => []]);
        $denied = [
            "UPDATE identity_ref.records SET mainid = 'x'",
            'UPDATE identity_ref.record_versions SET version_no = 9',
            'DELETE FROM identity_ref.record_versions',
            'DELETE FROM identity_ref.dataset_releases',
            'TRUNCATE identity_ref.release_presence',
            "INSERT INTO identity_ref.active_release (id, release_id) VALUES (1, {$release})",
            "UPDATE identity_ref.active_release SET release_id = {$release}",
            "UPDATE identity_ref.dataset_releases SET status = 'ABANDONED', abandoned_at = now()",
            "UPDATE identity_ref.import_runs SET manifest_sha256 = '".str_repeat('f', 64)."'",
            'ALTER TABLE identity_ref.records DISABLE TRIGGER ALL',
            'ALTER TABLE identity_ref.records DROP CONSTRAINT records_mainid_key',
            'DROP TABLE identity_ref.records',
            'DROP FUNCTION identity_ref.forbid_change()',
            'CREATE TABLE identity_ref.intruder (id int)',
            'CREATE OR REPLACE FUNCTION identity_ref.publish_release(p_release bigint) RETURNS bigint LANGUAGE sql AS $$ SELECT 0::bigint $$',
            'SET session_replication_role = replica',
        ];
        foreach ($denied as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::unprepared($sql))), $sql);
        }
        // Without a grant option PostgreSQL only warns; what matters is that nothing was granted.
        $this->asRole(self::WRITER, fn () => DB::unprepared('GRANT SELECT ON identity_ref.records TO '.self::APP));
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege(?, 'identity_ref.records', 'SELECT') AS p", [self::APP])->p);
        $this->assertSame(['900000050' => 'PRESENT'], $this->activeIdentities());
    }

    /**
     * SET ROLE from an administrator session cannot prove what a real login can
     * do (SET ROLE checks the SESSION user). So: a throwaway LOGIN role that is
     * only a member of the writer role opens its own connection.
     */
    public function test_a_real_writer_login_session_cannot_escalate(): void
    {
        $login = 'famboook_identity_writer_login_p1test';
        $password = bin2hex(random_bytes(16));
        DB::unprepared("DROP ROLE IF EXISTS {$login}");
        DB::unprepared("CREATE ROLE {$login} LOGIN INHERIT PASSWORD '{$password}' IN ROLE ".self::WRITER);
        try {
            $c = DB::connection()->getConfig();
            $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $login, $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->assertSame($login, $pdo->query('SELECT session_user')->fetchColumn());

            $release = $this->publishedRelease(['900000060' => []]);
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM identity_ref.records')->fetchColumn(), 'writer privileges apply');
            foreach ([
                'SET ROLE '.self::OWNER,
                'SET ROLE '.self::APP,
                'SET session_replication_role = replica',
                'ALTER TABLE identity_ref.records DISABLE TRIGGER ALL',
                'DELETE FROM identity_ref.records',
                "UPDATE identity_ref.active_release SET release_id = {$release}",
            ] as $sql) {
                $this->assertSame('42501', $this->sqlStateOf(fn () => $pdo->exec($sql)), $sql);
            }
            $pdo = null;
        } finally {
            DB::unprepared("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = '{$login}'");
            DB::unprepared("DROP ROLE IF EXISTS {$login}");
        }
    }

    public function test_roles_carry_no_special_attributes_or_memberships(): void
    {
        $roles = collect(DB::select('SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls, rolinherit, rolcanlogin
            FROM pg_roles WHERE rolname IN (?, ?, ?) ORDER BY rolname', [self::OWNER, self::READER, self::WRITER]));
        $this->assertCount(3, $roles);
        foreach ($roles as $r) {
            $this->assertFalse($r->rolsuper || $r->rolcreaterole || $r->rolcreatedb || $r->rolreplication || $r->rolbypassrls, $r->rolname);
            // The script creates every role NOLOGIN; a DBA enables LOGIN for the writer outside Git.
            $this->assertFalse((bool) $r->rolcanlogin, $r->rolname);
        }
        foreach ([[self::WRITER, self::OWNER], [self::APP, self::OWNER], [self::APP, self::WRITER], [self::READER, self::WRITER], [self::OWNER, self::APP]] as [$member, $of]) {
            $this->assertFalse((bool) DB::selectOne("SELECT pg_has_role(?, ?, 'MEMBER') AS m", [$member, $of])->m, "{$member} in {$of}");
        }
        $this->assertFalse((bool) DB::selectOne("SELECT has_schema_privilege(?, 'identity_ref', 'CREATE') AS p", [self::WRITER])->p);
        $this->assertFalse((bool) DB::selectOne("SELECT has_database_privilege(?, current_database(), 'CREATE') AS p", [self::WRITER])->p);
    }

    /** SECURITY DEFINER functions: owner-owned, pinned search_path, no PUBLIC execute. */
    public function test_functions_have_a_protected_search_path_and_no_public_execute(): void
    {
        $functions = collect(DB::select("SELECT p.proname, p.prosecdef, pg_get_userbyid(p.proowner) AS owner, p.proconfig,
                EXISTS (SELECT 1 FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a WHERE a.grantee = 0) AS public_execute
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'identity_ref' ORDER BY p.proname"));

        $this->assertSame(['abandon_release', 'publish_release'], $functions->where('prosecdef', true)->pluck('proname')->values()->all());
        foreach ($functions as $f) {
            $this->assertSame(self::OWNER, $f->owner, $f->proname);
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $f->proconfig, $f->proname);
            $this->assertFalse((bool) $f->public_execute, $f->proname);
        }
        foreach ([self::APP, self::READER] as $role) {
            foreach (['publish_release(bigint)', 'abandon_release(bigint)', 'carry_forward_missing(bigint)'] as $fn) {
                $this->assertFalse((bool) DB::selectOne('SELECT has_function_privilege(?, ?, \'EXECUTE\') AS p', [$role, 'identity_ref.'.$fn])->p);
            }
        }
    }
}
