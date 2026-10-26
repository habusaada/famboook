<?php

namespace Tests\Feature\IdentityRef;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U1 — the metadata-only monitoring boundary
 * (06-monitor.dba.sql; ADR §5, §9 PostgreSQL 1–4). Every check runs in a
 * REAL login session of a throwaway role; SYNTHETIC data only.
 */
class IdentityRefMonitorPostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    private const MONITOR = 'famboook_identity_monitor';

    private const VIEWS = ['active_release', 'file_locations', 'files', 'issue_counts', 'issue_locations', 'preparations', 'releases', 'runs'];

    private const LOCATION_VIEWS = ['file_locations', 'issue_locations'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef(withMonitor: true);
    }

    protected function tearDown(): void
    {
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function monitorSession(): Connection
    {
        $db = $this->loginConnection(self::MONITOR);
        $db->unprepared('SET ROLE '.self::MONITOR);

        return $db;
    }

    public function test_the_monitor_reads_every_view_and_nothing_else(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $monitor = $this->monitorSession();

        foreach (self::VIEWS as $view) {
            $this->assertNull($this->sqlStateOf(fn () => $monitor->select("SELECT * FROM identity_ref_monitor.{$view}")), $view);
        }
        $this->assertSame(1, (int) $monitor->selectOne('SELECT count(*) AS n FROM identity_ref_monitor.active_release')->n);

        foreach ([
            'SELECT count(*) FROM identity_ref.records',
            'SELECT count(*) FROM identity_ref.record_versions',
            'SELECT count(*) FROM identity_ref.release_presence',
            'SELECT count(*) FROM identity_ref.release_incoming',
            'SELECT count(*) FROM identity_ref.import_runs',
            'SELECT count(*) FROM identity_ref.import_files',
            'SELECT count(*) FROM identity_ref.import_issues',
            'SELECT count(*) FROM identity_ref.release_preparations',
            'SELECT count(*) FROM identity_ref_staging.rows',
            'SELECT count(*) FROM identity_ref_staging.assessments',
            'SELECT count(*) FROM identity_ref_staging.file_chunks',
            'SELECT identity_ref.publish_release(1)',
            "SELECT * FROM identity_ref_staging.assess_run('00000000-0000-0000-0000-000000000000', NULL, 'UNRESOLVED')",
            'SELECT identity_ref.refresh_statistics()',
            'CREATE TABLE identity_ref_monitor.intruder (id int)',
            'CREATE OR REPLACE VIEW identity_ref_monitor.runs AS SELECT 1 AS id',
            'SET ROLE famboook_identity_writer',
            'SET ROLE famboook_identity_owner',
        ] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $monitor->unprepared($sql)), $sql);
        }
    }

    public function test_the_login_alone_reads_nothing_before_set_role(): void
    {
        $login = $this->loginConnection(self::MONITOR);   // NOINHERIT
        $this->assertSame('42501', $this->sqlStateOf(fn () => $login->select('SELECT * FROM identity_ref_monitor.runs')));
    }

    public function test_views_expose_no_identity_value_hash_or_internal_id_and_names_only_in_location_views(): void
    {
        $columns = collect(DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = 'identity_ref_monitor' ORDER BY table_name, ordinal_position"))->groupBy('table_name');
        $this->assertSame(self::VIEWS, $columns->keys()->sort()->values()->all());

        foreach ($columns as $view => $cols) {
            $names = $cols->pluck('column_name')->all();
            foreach ($names as $name) {
                $this->assertDoesNotMatchRegularExpression('/payload|sha|fingerprint|mainid|source_value|national|name1|record_id|version_id|file_id/i', $name, "{$view}.{$name}");
            }
            in_array($view, self::LOCATION_VIEWS, true)
                ? $this->assertContains('file_name', $names, $view)
                : $this->assertNotContains('file_name', $names, "{$view}: file names only in the location views (D-UI-8)");
        }
        // Owner's rights, explicit; every view a security barrier.
        foreach (DB::select("SELECT c.relname, c.reloptions::text AS opts, pg_get_userbyid(c.relowner) AS owner FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'identity_ref_monitor'") as $v) {
            $this->assertSame(self::OWNER, $v->owner, $v->relname);
            $this->assertStringContainsString('security_barrier=true', (string) $v->opts, $v->relname);
            $this->assertStringContainsString('security_invoker=false', (string) $v->opts, $v->relname);
        }
    }

    public function test_view_content_is_metadata_only(): void
    {
        $secret = 'اسم-سري-تجريبي';
        $rows = $this->rows(['900000101' => ['name1' => $secret], '012345678' => ['phonenum' => '0599000001']]);
        $rows[] = SyntheticIdentityExport::row('900000101', ['name1' => $secret]);    // duplicate key → issue rows
        $run = $this->stagedRun($this->export(...$rows));

        $monitor = $this->monitorSession();
        $files = $monitor->select('SELECT * FROM identity_ref_monitor.files WHERE run_id = ?', [$run]);
        $this->assertSame([[1, 3, 3, 'STAGED', true]], array_map(fn ($f) => [(int) $f->file_no, (int) $f->expected_rows, (int) $f->loaded_rows,
            $f->status, (bool) $f->checksum_verified], $files));
        $this->assertSame(['MAINID_DUPLICATE' => 2], collect($monitor->select('SELECT code, n FROM identity_ref_monitor.issue_counts WHERE run_id = ?', [$run]))
            ->mapWithKeys(fn ($i) => [$i->code => (int) $i->n])->all());

        $everything = '';
        foreach (self::VIEWS as $view) {
            $everything .= json_encode($monitor->select("SELECT * FROM identity_ref_monitor.{$view}"), JSON_UNESCAPED_UNICODE);
        }
        foreach (['900000101', '012345678', '0599000001', $secret, 'اسم-تجريبي'] as $value) {
            $this->assertStringNotContainsString($value, $everything, $value);
        }
    }

    public function test_the_application_role_has_no_access_to_the_monitor_schema(): void
    {
        $this->assertFalse((bool) DB::selectOne("SELECT pg_has_role('famboook_app', ?, 'MEMBER') AS m", [self::MONITOR])->m);
        $this->assertFalse((bool) DB::selectOne("SELECT has_schema_privilege('famboook_app', 'identity_ref_monitor', 'USAGE') AS p")->p);
        foreach (self::VIEWS as $view) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::APP, fn () => DB::select("SELECT * FROM identity_ref_monitor.{$view}"))), $view);
        }
        foreach (['famboook_identity_monitor', 'famboook_identity_monitor_login'] as $role) {
            $r = DB::selectOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolinherit, rolcanlogin FROM pg_roles WHERE rolname = ?', [$role]);
            $this->assertSame([false, false, false, false, false, false], array_map('boolval', array_values((array) $r)), "{$role}: created NOLOGIN, no attribute");
        }
        $this->assertSame([self::MONITOR], array_column(DB::select("SELECT g.rolname FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid
            JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = 'famboook_identity_monitor_login'"), 'rolname'));
    }

    public function test_06_is_idempotent_and_04_restores_the_monitor_boundary_after_a_restore(): void
    {
        DB::unprepared($this->sqlFile('06-monitor.dba.sql'));   // second run
        $installed = $this->monitorState();

        // What pg_restore --no-owner --no-privileges leaves behind.
        DB::unprepared('REASSIGN OWNED BY '.self::OWNER.' TO CURRENT_USER');
        DB::unprepared('REVOKE ALL ON SCHEMA identity_ref_monitor FROM '.self::MONITOR);
        DB::unprepared('REVOKE ALL ON ALL TABLES IN SCHEMA identity_ref_monitor FROM '.self::MONITOR);
        DB::unprepared('GRANT SELECT ON ALL TABLES IN SCHEMA identity_ref_monitor TO PUBLIC');
        $this->assertNotEquals($installed, $this->monitorState());

        DB::unprepared($this->sqlFile('00-roles.dba.sql'));
        DB::unprepared($this->sqlFile('04-reassert-after-restore.dba.sql'));
        $this->assertEquals($installed, $this->monitorState(), 'identical to a fresh install');
        $this->assertNull($this->sqlStateOf(fn () => $this->monitorSession()->select('SELECT * FROM identity_ref_monitor.runs')));
        $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::APP, fn () => DB::select('SELECT * FROM identity_ref_monitor.runs'))));
    }

    /** Owner and explicit ACL of the monitor schema and its views (sorted ACL entries). */
    private function monitorState(): array
    {
        $acl = "(SELECT coalesce(string_agg(format('%s>%s:%s', pg_get_userbyid(a.grantor), CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
            a.privilege_type), ',' ORDER BY 1), '') FROM aclexplode(ACL) a)";

        return [
            'schema' => DB::select('SELECT pg_get_userbyid(nspowner) AS owner, '.str_replace('ACL', 'nspacl', $acl)." AS acl
                FROM pg_namespace WHERE nspname = 'identity_ref_monitor'"),
            'views' => DB::select('SELECT c.relname, pg_get_userbyid(c.relowner) AS owner, '.str_replace('ACL', 'c.relacl', $acl)." AS acl
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'identity_ref_monitor' ORDER BY 1"),
        ];
    }
}
