<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\Support\CivilImport\CivilImportHarness;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U2 — the control plane in PostgreSQL: the privileges of
 * 07-control-plane.dba.sql (checked with REAL roles), its separation from
 * every identity role, the request state machine enforced by the trigger, the
 * single-active-request index and the impossibility of queueing a
 * publication. PostgreSQL only; SYNTHETIC data only.
 */
class CivilImportControlPlanePostgresTest extends TestCase
{
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private const CONTROL = 'famboook_civil_control';

    private int $userId;

    private string $sourceId;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpIdentityRef(withMonitor: true);
        DB::unprepared($this->sqlFile('07-control-plane.dba.sql'));
        $this->userId = User::factory()->create()->id;
        $this->sourceId = (string) Str::uuid();
        DB::table('civil_import_sources')->insert(['id' => $this->sourceId, 'label' => 'export-a', 'manifest_status' => 'VALID']);
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            parent::tearDown();

            return;
        }
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        CivilImportHarness::truncateCivilImportTables();
        parent::tearDown();
    }

    private function request(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('civil_import_requests')->insert($overrides + ['id' => $id, 'type' => 'VALIDATE_SOURCE', 'requested_by' => $this->userId,
            'source_id' => $this->sourceId]);

        return $id;
    }

    public function test_the_control_role_holds_exactly_its_control_plane_privileges(): void
    {
        $id = $this->request();
        // A REAL login session (member of the control role only), as the worker's: SET ROLE is checked against it.
        $control = $this->loginConnection(self::CONTROL);
        $control->unprepared('SET ROLE '.self::CONTROL);
        $denied = fn (string $sql) => $this->assertSame('42501', $this->sqlStateOf(fn () => $control->unprepared($sql)), $sql);
        $allowed = fn (string $sql) => $this->assertNull($this->sqlStateOf(fn () => $control->unprepared($sql)), $sql);

        $allowed('SELECT id, status, result FROM civil_import_requests');
        $allowed("UPDATE civil_import_requests SET status = 'RUNNING', started_at = now(), attempts = 1, worker_instance = 'w' WHERE id = '{$id}'");
        $allowed("UPDATE civil_import_requests SET heartbeat_at = now() WHERE id = '{$id}'");
        $denied("UPDATE civil_import_requests SET type = 'STAGE' WHERE id = '{$id}'");
        $denied("UPDATE civil_import_requests SET requested_by = NULL WHERE id = '{$id}'");
        $denied("UPDATE civil_import_requests SET cancel_requested_at = now() WHERE id = '{$id}'");
        $denied("INSERT INTO civil_import_requests (id, type, source_id) VALUES ('".Str::uuid()."', 'VALIDATE_SOURCE', '{$this->sourceId}')");
        $denied("DELETE FROM civil_import_requests WHERE id = '{$id}'");
        $allowed("INSERT INTO civil_import_sources (id, label, manifest_status) VALUES ('".Str::uuid()."', 'export-b', 'VALID')");
        $allowed("UPDATE civil_import_sources SET available = false WHERE label = 'export-b'");
        $denied("DELETE FROM civil_import_sources WHERE label = 'export-b'");
        $allowed("INSERT INTO civil_import_worker_heartbeats (instance, state) VALUES ('w', 'IDLE')");
        $allowed("INSERT INTO civil_import_audit_events (event_type, actor_side) VALUES ('REQUEST_STARTED', 'SYSTEM')");
        $denied('SELECT event_type FROM civil_import_audit_events');
        $denied("UPDATE civil_import_audit_events SET outcome_code = 'X_Y'");
        // Nothing else in the application schema, nothing in any identity schema.
        $denied('SELECT id FROM users');
        $denied('SELECT 1 FROM personal_access_tokens');
        foreach (['identity_ref.import_runs', 'identity_ref.records', 'identity_ref_staging.rows', 'identity_ref_monitor.runs'] as $relation) {
            $denied("SELECT 1 FROM {$relation}");
        }
        foreach (['famboook_identity_writer', 'famboook_identity_owner', 'famboook_identity_monitor', 'famboook_identity_reader'] as $role) {
            $denied("SET ROLE {$role}");
        }
        // Without SET ROLE (NOINHERIT) the login itself holds nothing.
        $bare = $this->loginConnection(self::CONTROL);
        $this->assertSame('42501', $this->sqlStateOf(fn () => $bare->unprepared('SELECT 1 FROM civil_import_requests')));
    }

    public function test_identity_roles_and_the_application_role_cannot_touch_the_control_plane(): void
    {
        $this->request();
        foreach (['famboook_identity_writer', 'famboook_identity_owner', 'famboook_identity_monitor', 'famboook_identity_reader', self::APP] as $role) {
            // A real login that is a member of $role only.
            $session = $this->loginConnection($role);
            $session->unprepared('SET ROLE '.$role);
            $this->assertSame('42501', $this->sqlStateOf(fn () => $session->unprepared('SELECT 1 FROM civil_import_requests')), $role);
            $this->assertSame('42501', $this->sqlStateOf(fn () => $session->unprepared('SET ROLE '.self::CONTROL)), $role);
        }
        // The roles exist NOLOGIN; the login is a member of the control role only.
        $roles = collect(DB::select("SELECT rolname, rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname LIKE 'famboook_civil_control%' ORDER BY 1"))
            ->map(fn ($r) => [$r->rolname, $r->rolcanlogin, $r->rolinherit, $r->rolsuper])->all();
        $this->assertSame([['famboook_civil_control', false, false, false], ['famboook_civil_control_login', false, false, false]], $roles);
        $groups = collect(DB::select("SELECT g.rolname FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles r ON r.oid = m.member
            WHERE r.rolname = 'famboook_civil_control_login'"))->pluck('rolname')->all();
        $this->assertSame(['famboook_civil_control'], $groups);
    }

    public function test_07_is_idempotent_and_refuses_to_mix_control_and_identity_roles(): void
    {
        $snapshot = fn () => collect(DB::select("SELECT table_name, privilege_type FROM information_schema.role_table_grants
            WHERE grantee = 'famboook_civil_control' ORDER BY 1, 2"))->map(fn ($r) => $r->table_name.':'.$r->privilege_type)->all();
        $columns = fn () => collect(DB::select("SELECT column_name FROM information_schema.column_privileges
            WHERE grantee = 'famboook_civil_control' AND table_name = 'civil_import_requests' AND privilege_type = 'UPDATE' ORDER BY 1"))->pluck('column_name')->all();
        $before = [$snapshot(), $columns()];
        DB::unprepared($this->sqlFile('07-control-plane.dba.sql'));
        $this->assertSame($before, [$snapshot(), $columns()]);
        $this->assertSame(['attempts', 'finished_at', 'heartbeat_at', 'message', 'reason_code', 'release_id', 'result', 'run_id',
            'started_at', 'status', 'worker_instance'], $before[1]);

        DB::unprepared('GRANT famboook_identity_writer TO '.self::CONTROL);
        try {
            DB::unprepared($this->sqlFile('07-control-plane.dba.sql'));
            $this->fail('07 accepted a control role that is an identity role member');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CIVIL_IMPORT_CONTROL_MUST_NOT_SHARE_IDENTITY_ROLES', $e->getMessage());
        } finally {
            DB::unprepared('REVOKE famboook_identity_writer FROM '.self::CONTROL);
        }
    }

    public function test_the_trigger_enforces_the_request_state_machine(): void
    {
        $sqlState = fn (string $sql, array $bindings = []) => $this->sqlStateOf(fn () => DB::statement($sql, $bindings));
        $id = $this->request();

        $this->assertSame('23514', $sqlState("UPDATE civil_import_requests SET status = 'SUCCEEDED', finished_at = now() WHERE id = ?", [$id]));
        $this->assertSame('23514', $sqlState("UPDATE civil_import_requests SET status = 'RUNNING' WHERE id = ?", [$id]));   // no start, no attempt
        $this->assertNull($sqlState("UPDATE civil_import_requests SET status = 'RUNNING', started_at = now(), attempts = 1 WHERE id = ?", [$id]));
        $this->assertSame('23514', $sqlState("UPDATE civil_import_requests SET status = 'CANCELLED', finished_at = now() WHERE id = ?", [$id]));
        $this->assertSame('23514', $sqlState("UPDATE civil_import_requests SET status = 'SUCCEEDED' WHERE id = ?", [$id]));   // no end
        $this->assertSame('23001', $sqlState("UPDATE civil_import_requests SET type = 'STAGE' WHERE id = ?", [$id]));
        $this->assertSame('23001', $sqlState('UPDATE civil_import_requests SET requested_by = NULL WHERE id = ?', [$id]));
        $this->assertNull($sqlState("UPDATE civil_import_requests SET status = 'FAILED', finished_at = now(), reason_code = 'WORKER_LOST' WHERE id = ?", [$id]));
        $this->assertSame('23001', $sqlState("UPDATE civil_import_requests SET reason_code = 'OTHER_CODE' WHERE id = ?", [$id]));
        $this->assertSame('23001', $sqlState('DELETE FROM civil_import_requests WHERE id = ?', [$id]));

        // RUNNING → CANCELLED only after a cancellation request.
        $other = $this->request();
        DB::statement("UPDATE civil_import_requests SET status = 'RUNNING', started_at = now(), attempts = 1 WHERE id = ?", [$other]);
        $this->assertSame('23514', $sqlState("UPDATE civil_import_requests SET status = 'CANCELLED', finished_at = now() WHERE id = ?", [$other]));
        $this->assertNull($sqlState("UPDATE civil_import_requests SET status = 'CANCELLED', finished_at = now(), cancel_requested_at = now() WHERE id = ?", [$other]));

        // A request starts QUEUED; PUBLISH cannot be queued in U2.
        $this->assertSame('23514', $sqlState("INSERT INTO civil_import_requests (id, type, status, source_id) VALUES (?, 'VALIDATE_SOURCE', 'RUNNING', ?)",
            [(string) Str::uuid(), $this->sourceId]));
        $this->assertSame('23514', $sqlState("INSERT INTO civil_import_requests (id, type, release_id) VALUES (?, 'PUBLISH', 4)", [(string) Str::uuid()]));
        $this->assertSame('23514', $sqlState("INSERT INTO civil_import_requests (id, type) VALUES (?, 'ASSESS')", [(string) Str::uuid()]));   // no target
    }

    public function test_only_one_request_can_be_active(): void
    {
        $this->request();
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->request()));
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->request(['type' => 'STAGE'])));
        // Idempotency keys are unique per requester.
        DB::statement("UPDATE civil_import_requests SET status = 'CANCELLED', finished_at = now()");
        $this->request(['idempotency_key' => 'abcdefgh-1']);
        DB::statement("UPDATE civil_import_requests SET status = 'CANCELLED', finished_at = now() WHERE status = 'QUEUED'");
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->request(['idempotency_key' => 'abcdefgh-1'])));
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
        } catch (\PDOException) {
            return false;
        }
    }
}
