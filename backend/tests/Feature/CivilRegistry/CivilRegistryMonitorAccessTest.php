<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U1 — access, configuration and architecture rules of the
 * civil import monitoring API that need no identity schema (runs in the
 * default SQLite suite too). The data path is covered by
 * CivilRegistryMonitorApiPostgresTest. SYNTHETIC data only.
 */
class CivilRegistryMonitorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '00000000-0000-4000-8000-000000000000';

    private const PATHS = ['/overview', '/imports', '/imports/'.self::RUN, '/imports/'.self::RUN.'/issues', '/releases', '/releases/1',
        '/imports/'.self::RUN.'/files/locations', '/imports/'.self::RUN.'/issues/locations', '/audit'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['civil_import.monitor_credentials_file' => base_path('storage/framework/testing/no-such-identity-monitor.php')]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_every_endpoint_requires_authentication_and_staff_without_permission_is_refused(): void
    {
        foreach (self::PATHS as $path) {
            $this->getJson('/api/v1/civil-registry'.$path)->assertUnauthorized();
        }
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $user = $this->user($role);
            foreach (self::PATHS as $path) {
                $this->actingAs($user)->getJson('/api/v1/civil-registry'.$path)->assertForbidden();
            }
        }
    }

    public function test_without_the_protected_credentials_file_monitoring_answers_503_and_never_falls_back(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        foreach (['/overview', '/imports', '/imports/'.self::RUN, '/releases'] as $path) {
            $this->actingAs($admin)->getJson('/api/v1/civil-registry'.$path)
                ->assertStatus(503)
                ->assertExactJson(['message' => 'Civil import monitoring is not configured on this server.', 'code' => 'MONITOR_NOT_CONFIGURED']);
        }
        // A malformed credentials file is refused the same way.
        $file = tempnam(sys_get_temp_dir(), 'famboook-monitor-');
        file_put_contents($file, "<?php return ['username' => 'Robert\"); DROP ROLE x; --', 'password' => 'p'];");
        try {
            config(['civil_import.monitor_credentials_file' => $file]);
            $this->getJson('/api/v1/civil-registry/overview')->assertStatus(503)->assertJsonPath('code', 'MONITOR_NOT_CONFIGURED');
        } finally {
            unlink($file);
        }
        $this->assertNull(config('database.connections.identity_ref_monitor.username'));
        // The audit endpoint reads the application database only.
        $this->getJson('/api/v1/civil-registry/audit')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_monitor_credentials_are_never_part_of_the_configuration(): void
    {
        $this->assertNull(config('database.connections.identity_ref_monitor.username'));
        $this->assertNull(config('database.connections.identity_ref_monitor.password'));
        $this->assertSame('identity_ref_monitor', config('database.connections.identity_ref_monitor.search_path'));
        foreach (['config/database.php', 'config/civil_import.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression("/env\\('IDENTITY_REF_MONITOR_DB_(USERNAME|PASSWORD)'|env\\('CIVIL_IMPORT_[A-Z_]*PASSWORD'/",
                (string) file_get_contents(base_path($file)), $file);
        }
        $this->assertFalse((bool) config('civil_import.publish_enabled'), 'publication gate closed by default (S-4)');
    }

    /** S-1: nothing reachable over HTTP can execute an identity import. */
    public function test_no_http_reachable_class_can_execute_an_import(): void
    {
        // U2: the worker side (worker, catalogue, file verification) is never reachable from HTTP either.
        $forbidden = '/identity_ref_import|IdentityImportSession|StageIdentityImportAction|PublishIdentityReleaseAction|famboook_identity_writer'
            .'|IdentityImportWorker|CivilImportSourceCatalogue|IdentitySourceVerifier|IdentityManifest\b/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http')));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $checked++;
                $this->assertDoesNotMatchRegularExpression($forbidden, (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
        $this->assertGreaterThan(50, $checked);
        $this->assertDoesNotMatchRegularExpression($forbidden, (string) file_get_contents(base_path('routes/api.php')));
        // U2: writes exist only on the execution controller, whose action only records requests.
        $routes = (string) file_get_contents(base_path('routes/api.php'));
        $this->assertDoesNotMatchRegularExpression('/Route::(post|put|patch|delete)\([^\n]*CivilImport(Monitor|Audit)Controller/', $routes);
        foreach (['app/Actions/QueueCivilImportRequestAction.php', 'app/Actions/CancelCivilImportRequestAction.php',
            'app/Support/CivilImport/CivilImportControlPlane.php', 'app/Actions/RequestCivilImportPublicationAction.php',
            'app/Actions/DecideCivilImportPublicationAction.php', 'app/Support/CivilImport/CivilImportPublications.php'] as $httpReachable) {
            $this->assertDoesNotMatchRegularExpression($forbidden, (string) file_get_contents(base_path($httpReachable)), $httpReachable);
        }
    }

    public function test_the_permission_baseline_grants_the_civil_import_permissions_to_super_admin_only(): void
    {
        $civil = ['civil-import.view', 'civil-import.locations.view', 'civil-import.audit.view', 'civil-import.stage', 'civil-import.validate',
            'civil-import.publish.request'];
        foreach ($civil as $permission) {
            $this->assertContains($permission, RolePermissionSeeder::PERMISSIONS);
        }
        foreach (RolePermissionSeeder::ROLES as $role) {
            $held = array_values(array_intersect($civil, RolePermissionSeeder::rolePermissions($role)));
            $this->assertSame($role === 'SUPER_ADMIN' ? $civil : [], $held, $role);
        }
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'), Artisan::output());

        // U3: approving publication exists only while the publication gate is open, for SUPER_ADMIN only (S-4).
        $this->assertContains('civil-import.publish.approve', RolePermissionSeeder::PERMISSIONS);
        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertNotContains('civil-import.publish.approve', RolePermissionSeeder::rolePermissions($role), $role);
        }
        config(['civil_import.publish_enabled' => true]);
        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertSame($role === 'SUPER_ADMIN', in_array('civil-import.publish.approve', RolePermissionSeeder::rolePermissions($role), true), $role);
        }
        // The seeded database (gate closed) no longer matches an open gate: the verifier says so.
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('must have civil-import.publish.approve', Artisan::output());
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'), Artisan::output());
        config(['civil_import.publish_enabled' => false]);
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('must NOT have civil-import.publish.approve', Artisan::output());
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'), Artisan::output());

        // Never a direct grant.
        $user = User::factory()->create();
        $user->assignRole('REPORTS_VIEWER');
        $user->givePermissionTo('civil-import.publish.request');
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('publication permission grant(s) made directly', Artisan::output());
    }

    public function test_audit_details_accept_codes_and_counts_only_and_events_are_immutable(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        foreach ([['scope' => 'files'], ['note' => 'FILES'], ['code' => '900000001x'], ['count' => -1], ['scope' => 't03maindata_part_00001.csv']] as $details) {
            try {
                CivilImportAudit::record(CivilImportAudit::LOCATIONS_VIEWED, $admin, self::RUN, null, null, $details);
                $this->fail('accepted '.json_encode($details));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        try {
            CivilImportAudit::record('ANYTHING_ELSE', $admin);
        } finally {
            $event = CivilImportAudit::record(CivilImportAudit::LOCATIONS_VIEWED, $admin, self::RUN, null, null, ['scope' => 'FILES', 'page' => 2]);
            $this->assertSame('STAFF', $event->actor_side);
            try {
                $event->update(['outcome_code' => 'X_CHANGED']);
                $this->fail('updated an audit event');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            try {
                $event->delete();
                $this->fail('deleted an audit event');
            } catch (LogicException) {
                $this->assertSame(1, CivilImportAuditEvent::count());
            }
        }
    }
}
