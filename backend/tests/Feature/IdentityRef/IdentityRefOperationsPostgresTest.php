<?php

namespace Tests\Feature\IdentityRef;

use App\Console\Commands\StageIdentityImport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P4 — operational safety: privileges after a restore, the
 * import login's server-log settings, and the Production approval gate.
 * PostgreSQL only, disposable test cluster, no data from any real source.
 */
class IdentityRefOperationsPostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefStaging;

    private const IMPORT_LOGIN = 'famboook_identity_import';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef(withPublication: true);
    }

    protected function tearDown(): void
    {
        putenv(StageIdentityImport::PRODUCTION_APPROVAL_VARIABLE);
        $this->tearDownIdentityRefStaging();
        if (DB::connection()->getDriverName() === 'pgsql'
            && DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [self::IMPORT_LOGIN]) !== null) {
            DB::unprepared("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = '".self::IMPORT_LOGIN."'");
            DB::unprepared('REVOKE CONNECT ON DATABASE famboook_test FROM '.self::IMPORT_LOGIN);
            DB::unprepared('DROP ROLE '.self::IMPORT_LOGIN);
        }
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    /** Owners and explicit privileges of every identity object (ACL entries sorted, so the order of grants does not matter). */
    private function privilegeState(): array
    {
        $aclOf = "(SELECT coalesce(string_agg(format('%s>%s:%s%s', pg_get_userbyid(a.grantor),
                    CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, a.privilege_type,
                    CASE WHEN a.is_grantable THEN '*' ELSE '' END), ',' ORDER BY 1), '') FROM aclexplode(ACL_COLUMN) a)";
        $acl = fn (string $column) => str_replace('ACL_COLUMN', $column, $aclOf);

        return [
            'schemas' => DB::select('SELECT nspname AS name, pg_get_userbyid(nspowner) AS owner, '.$acl('nspacl')." AS acl
                FROM pg_namespace WHERE nspname LIKE 'identity_ref%' ORDER BY 1"),
            'relations' => DB::select('SELECT n.nspname || \'.\' || c.relname AS name, c.relkind, pg_get_userbyid(c.relowner) AS owner, '.$acl('c.relacl')." AS acl
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname LIKE 'identity_ref%' AND c.relkind IN ('r', 'v', 'S', 'p') ORDER BY 1"),
            'columns' => DB::select('SELECT c.relname || \'.\' || a.attname AS name, '.$acl('a.attacl')." AS acl
                FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname LIKE 'identity_ref%' AND a.attacl IS NOT NULL ORDER BY 1"),
            'functions' => DB::select('SELECT p.oid::regprocedure::text AS name, pg_get_userbyid(p.proowner) AS owner, p.prosecdef, '.$acl('p.proacl')." AS acl
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname LIKE 'identity_ref%' ORDER BY 1"),
        ];
    }

    public function test_reassert_restores_the_exact_ownership_and_privileges_after_a_restore(): void
    {
        $installed = $this->privilegeState();
        $this->assertNotEmpty($installed['columns'], 'column-level grants exist and are covered');

        // What pg_restore --no-owner --no-privileges leaves behind: objects owned by the restoring role, no grants.
        DB::unprepared('REASSIGN OWNED BY '.self::OWNER.' TO CURRENT_USER');
        foreach (['identity_ref', 'identity_ref_staging'] as $schema) {
            DB::unprepared("REVOKE ALL ON ALL TABLES IN SCHEMA {$schema} FROM PUBLIC, ".self::WRITER.', '.self::READER);
            DB::unprepared("REVOKE ALL ON ALL FUNCTIONS IN SCHEMA {$schema} FROM PUBLIC, ".self::WRITER.', '.self::READER);
            DB::unprepared("REVOKE ALL ON SCHEMA {$schema} FROM PUBLIC, ".self::WRITER.', '.self::READER);
            DB::unprepared("GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA {$schema} TO PUBLIC");   // pg_restore --no-privileges: default EXECUTE for PUBLIC
        }
        $restored = $this->privilegeState();
        $this->assertNotEquals($installed, $restored);
        $this->assertSame([DB::selectOne('SELECT current_user AS u')->u], array_values(array_unique(array_column($restored['functions'], 'owner'))),
            'after a restore the SECURITY DEFINER functions would run as the restoring superuser');

        DB::unprepared($this->sqlFile('00-roles.dba.sql'));
        DB::unprepared($this->sqlFile('04-reassert-after-restore.dba.sql'));
        $this->assertEquals($installed, $this->privilegeState(), 'identical to a fresh install');

        // Idempotent.
        DB::unprepared($this->sqlFile('04-reassert-after-restore.dba.sql'));
        $this->assertEquals($installed, $this->privilegeState());
    }

    public function test_reassert_refuses_without_the_roles_or_the_schema(): void
    {
        DB::unprepared($this->sqlFile('90-drop.local-test-only.sql'));
        $this->assertNotNull($this->sqlStateOf(fn () => DB::unprepared($this->sqlFile('04-reassert-after-restore.dba.sql'))));
    }

    public function test_the_import_login_is_least_privileged_and_its_errors_stay_out_of_the_server_log(): void
    {
        DB::unprepared($this->sqlFile('05-import-login.dba.sql'));
        DB::unprepared($this->sqlFile('05-import-login.dba.sql'));   // idempotent

        $role = DB::selectOne('SELECT rolcanlogin, rolinherit, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = ?', [self::IMPORT_LOGIN]);
        $this->assertSame([false, false, false, false, false, false, false], array_map('boolval', array_values((array) $role)), 'created NOLOGIN, NOINHERIT, no attribute');
        $this->assertSame([self::WRITER], array_column(DB::select('SELECT g.rolname FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid
            JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = ?', [self::IMPORT_LOGIN]), 'rolname'));

        // A real session of the login (enabled only for this test, random password).
        $password = bin2hex(random_bytes(16));
        DB::unprepared('ALTER ROLE '.self::IMPORT_LOGIN." LOGIN PASSWORD '{$password}'");
        $c = DB::connection()->getConfig();
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", self::IMPORT_LOGIN, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['log_min_messages' => 'panic', 'log_min_error_statement' => 'panic', 'log_statement' => 'none', 'log_min_duration_statement' => '-1'] as $setting => $value) {
            $this->assertSame($value, $pdo->query("SHOW {$setting}")->fetchColumn(), $setting);
        }
        $this->assertSame('42501', $this->sqlStateOf(fn () => $pdo->exec('SELECT count(*) FROM identity_ref.records')), 'NOINHERIT: no privilege before SET ROLE');
        $pdo->exec('SET ROLE '.self::WRITER);
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM identity_ref.records')->fetchColumn());
        $this->assertSame('42501', $this->sqlStateOf(fn () => $pdo->exec('SET log_min_messages = warning')), 'the login cannot switch logging back on');
        $this->assertSame('42501', $this->sqlStateOf(fn () => $pdo->exec('SET ROLE '.self::OWNER)));
        $pdo = null;
    }

    /** The cached configuration never holds the import login: the command takes it from the operator's shell. */
    public function test_import_credentials_come_from_the_shell_not_from_cached_configuration(): void
    {
        $this->assertNull(config('database.connections.identity_ref_import.username'), 'the repository configuration holds no login');
        $this->assertNull(config('database.connections.identity_ref_import.password'));
        config(['database.connections.identity_ref_import.host' => config('database.connections.pgsql.host'),
            'database.connections.identity_ref_import.port' => config('database.connections.pgsql.port'),
            'database.connections.identity_ref_import.database' => 'famboook_test']);

        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[NOT_CONFIGURED]', Artisan::output());

        $login = $this->writerConnection()->getConfig();
        putenv('IDENTITY_REF_DB_USERNAME='.$login['username']);
        putenv('IDENTITY_REF_DB_PASSWORD='.$login['password']);
        try {
            $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
            $this->assertStringContainsString('Active release: none', Artisan::output());
            $this->assertStringNotContainsString($login['password'], Artisan::output());
        } finally {
            putenv('IDENTITY_REF_DB_USERNAME');
            putenv('IDENTITY_REF_DB_PASSWORD');
        }
    }

    public function test_production_requires_an_approval_reference_and_confirmation(): void
    {
        config(['database.connections.identity_ref_import' => $this->writerConnection()->getConfig()]);
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[NOT_APPROVED]', Artisan::output());
        putenv(StageIdentityImport::PRODUCTION_APPROVAL_VARIABLE.'=x');
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[NOT_APPROVED]', Artisan::output(), 'a malformed reference is refused');

        putenv(StageIdentityImport::PRODUCTION_APPROVAL_VARIABLE.'=CHG-2026-1025');
        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('PRODUCTION — approval CHG-2026-1025', Artisan::output());

        $run = '00000000-0000-4000-8000-000000000000';
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--prepare' => true, '--run' => $run, '--target' => 'famboook_test', '--no-interaction' => true]));
        $this->assertStringContainsString('[NOT_CONFIRMED]', Artisan::output(), 'a writing mode needs confirmation');
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--prepare' => true, '--run' => $run, '--target' => 'famboook_test', '--yes' => true]));
        $this->assertStringContainsString('[VALIDATION_FAILED]', Artisan::output(), 'confirmed: the normal validation applies');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);
    }
}
