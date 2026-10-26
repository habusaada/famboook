<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\CivilImport\CivilImportHarness;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U1 — the read-only civil import monitoring API over the
 * real monitor boundary (ADR §9 Laravel; API-and-screens §2). The API reads
 * through a throwaway LOGIN that is a member of famboook_identity_monitor
 * only. PostgreSQL only (phpunit.pgsql.xml); SYNTHETIC data only.
 */
class CivilRegistryMonitorApiPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private const SECRET_NAME = 'اسم-سري-تجريبي';

    private const VALUES = ['900000201', '900000202', '012345678', '0599000002', self::SECRET_NAME, 'اسم-تجريبي', 'عائلة-تجريبية'];

    private User $superAdmin;

    private User $administrator;

    private User $viewer;

    private string $publishedRun;

    private int $publishedRelease;

    private string $stagedRun;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpIdentityRef(withMonitor: true);

        $published = $this->importAndPublish(['900000201' => [], '012345678' => ['phonenum' => '0599000002']]);
        $this->publishedRun = $published['run_id'];
        $this->publishedRelease = $published['release_id'];
        $rows = $this->rows(['900000202' => ['name1' => self::SECRET_NAME]]);
        $rows[] = SyntheticIdentityExport::row('900000202', ['name1' => self::SECRET_NAME]);   // duplicate → 2 issue rows
        $this->stagedRun = $this->stagedRun($this->export(...$rows));

        // The API's monitoring login: a throwaway member of the monitor role only.
        $login = $this->loginConnection('famboook_identity_monitor')->getConfig();
        config(['database.connections.identity_ref_monitor.username' => $login['username'],
            'database.connections.identity_ref_monitor.password' => $login['password']]);
        DB::purge('identity_ref_monitor');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('SUPER_ADMIN');
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole('ADMINISTRATOR');
        // A staff account (staff.side needs a staff role) holding civil-import.view only, for
        // this test: REPORTS_VIEWER holds no civil-import.* permission in the baseline.
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('REPORTS_VIEWER');
        $this->viewer->givePermissionTo('civil-import.view');
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {   // setUp skipped before booting (SQLite suite)
            parent::tearDown();

            return;
        }
        DB::purge('identity_ref_monitor');
        CivilImportHarness::truncateCivilImportTables();
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function assertNoIdentityValue(string $json, string $context): void
    {
        foreach (self::VALUES as $value) {
            $this->assertStringNotContainsString($value, $json, "{$context}: {$value}");
        }
    }

    public function test_overview_list_detail_issues_and_releases_return_metadata_only(): void
    {
        $base = '/api/v1/civil-registry';
        $overview = $this->actingAs($this->viewer)->getJson("{$base}/overview")->assertOk();
        $overview->assertJsonPath('data.active_release.id', $this->publishedRelease)
            ->assertJsonPath('data.active_release.record_count', 2)
            ->assertJsonPath('data.current_run.id', $this->stagedRun)
            ->assertJsonPath('data.current_run.phase', 'STAGED')
            ->assertJsonPath('data.publication_enabled', false)
            ->assertJsonPath('data.worker.state', 'NOT_INSTALLED');

        $list = $this->getJson("{$base}/imports")->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['STAGED', 'PUBLISHED'], array_column($list->json('data'), 'phase'));

        $detail = $this->getJson("{$base}/imports/{$this->stagedRun}")->assertOk()
            ->assertJsonPath('data.files.0.file_no', 1)
            ->assertJsonPath('data.files.0.loaded_rows', 2)
            ->assertJsonPath('data.files.0.checksum_verified', true)
            ->assertJsonPath('data.allowed_actions', [])
            ->assertJsonPath('data.release', null);
        $this->assertArrayNotHasKey('file_name', $detail->json('data.files.0'), 'D-UI-8: no file name without locations.view');

        $this->getJson("{$base}/imports/{$this->stagedRun}/issues")->assertOk()
            ->assertExactJson(['data' => [['code' => 'MAINID_DUPLICATE', 'column' => 'mainid', 'rows' => 2]]]);

        $releases = $this->getJson("{$base}/releases")->assertOk();
        $release = $this->getJson("{$base}/releases/{$this->publishedRelease}")->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.preparation.policy_id', 'IDREF-POLICY-1')
            ->assertJsonPath('data.preparation.blank_field_policy', 'PRESERVE_EXISTING_ON_BLANK')
            ->assertJsonPath('data.preparation.quarantine', 'ZERO_UNRESOLVED_QUARANTINE')
            ->assertJsonPath('data.preparation.counts.new', 2);

        foreach ([$overview, $list, $detail, $releases, $release] as $i => $response) {
            $json = $response->getContent();
            $this->assertNoIdentityValue($json, "response {$i}");
            $this->assertStringNotContainsString('.csv', $json, "response {$i}: no file name");
            $this->assertDoesNotMatchRegularExpression('/sha256|payload|fingerprint|mainid"/i', $json, "response {$i}");
        }
    }

    public function test_locations_need_their_own_permission_and_every_read_is_audited(): void
    {
        $base = "/api/v1/civil-registry/imports/{$this->stagedRun}";
        $this->actingAs($this->viewer)->getJson("{$base}/files/locations")->assertForbidden();
        $this->getJson("{$base}/issues/locations")->assertForbidden();
        $this->assertSame(0, CivilImportAuditEvent::count());

        $files = $this->actingAs($this->superAdmin)->getJson("{$base}/files/locations")->assertOk()
            ->assertExactJson(['data' => [['file_no' => 1, 'file_name' => 't03maindata_part_00001.csv']]]);
        $issues = $this->getJson("{$base}/issues/locations?code=MAINID_DUPLICATE")->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.file_name', 't03maindata_part_00001.csv')
            ->assertJsonPath('data.0.line_number', 2)
            ->assertJsonPath('data.1.line_number', 3);
        $this->assertNoIdentityValue($files->getContent().$issues->getContent(), 'locations');

        $events = CivilImportAuditEvent::orderBy('id')->get();
        $this->assertSame([['LOCATIONS_VIEWED', 'STAFF', $this->superAdmin->id, $this->stagedRun, ['scope' => 'FILES']],
            ['LOCATIONS_VIEWED', 'STAFF', $this->superAdmin->id, $this->stagedRun, ['code' => 'MAINID_DUPLICATE', 'page' => 1, 'scope' => 'ISSUES']]],
            $events->map(function ($e) {
                $details = $e->details;
                ksort($details);   // jsonb stores keys in its own order

                return [$e->event_type, $e->actor_side, $e->actor_user_id, $e->run_id, $details];
            })->all());

        $audit = $this->getJson('/api/v1/civil-registry/audit?run='.$this->stagedRun)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.event_type', 'LOCATIONS_VIEWED')
            ->assertJsonPath('data.0.actor.side', 'STAFF')
            ->assertJsonPath('data.0.actor.name', $this->superAdmin->name);
        $this->assertStringNotContainsString($this->superAdmin->email, $audit->getContent());
        $this->assertStringNotContainsString('.csv', $audit->getContent(), 'audit rows hold no file name');
    }

    public function test_every_endpoint_requires_authentication_and_its_permission(): void
    {
        $run = $this->stagedRun;
        $view = ['/overview', '/imports', "/imports/{$run}", "/imports/{$run}/issues", '/releases', "/releases/{$this->publishedRelease}"];
        $locations = ["/imports/{$run}/files/locations", "/imports/{$run}/issues/locations"];
        $all = [...$view, ...$locations, '/audit'];

        foreach ($all as $path) {
            $this->getJson('/api/v1/civil-registry'.$path)->assertUnauthorized();
        }
        foreach ($all as $path) {
            $this->actingAs($this->administrator)->getJson('/api/v1/civil-registry'.$path)->assertForbidden();
        }
        foreach ([...$locations, '/audit'] as $path) {
            $this->actingAs($this->viewer)->getJson('/api/v1/civil-registry'.$path)->assertForbidden();
        }
        foreach ($all as $path) {
            $this->actingAs($this->superAdmin)->getJson('/api/v1/civil-registry'.$path)->assertOk();
        }
        $this->getJson('/api/v1/civil-registry/imports/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->getJson('/api/v1/civil-registry/imports/not-a-uuid')->assertNotFound();
        $this->getJson('/api/v1/civil-registry/releases/999999')->assertNotFound();
        $this->getJson('/api/v1/civil-registry/imports?status=EVIL')->assertUnprocessable();
    }

    public function test_the_monitor_refuses_a_login_that_is_not_metadata_only(): void
    {
        // The cluster administrator (a superuser) as the monitoring login: refused, never used.
        $admin = config('database.connections.pgsql');
        config(['database.connections.identity_ref_monitor.username' => $admin['username'],
            'database.connections.identity_ref_monitor.password' => $admin['password']]);
        DB::purge('identity_ref_monitor');
        $this->actingAs($this->superAdmin)->getJson('/api/v1/civil-registry/overview')
            ->assertStatus(503)->assertExactJson(['message' => 'Civil import monitoring is temporarily unavailable.', 'code' => 'MONITOR_UNAVAILABLE']);

        // A login that can act as the identity writer: refused too.
        $writer = $this->loginConnection('famboook_identity_writer')->getConfig();
        DB::unprepared("GRANT famboook_identity_monitor TO {$writer['username']}");
        config(['database.connections.identity_ref_monitor.username' => $writer['username'],
            'database.connections.identity_ref_monitor.password' => $writer['password']]);
        DB::purge('identity_ref_monitor');
        $this->getJson('/api/v1/civil-registry/overview')->assertStatus(503)->assertJsonPath('code', 'MONITOR_UNAVAILABLE');
    }

    public function test_the_audit_trail_is_append_only_in_the_database(): void
    {
        $this->actingAs($this->superAdmin)->getJson("/api/v1/civil-registry/imports/{$this->stagedRun}/files/locations")->assertOk();
        $id = CivilImportAuditEvent::value('id');
        $this->assertNotNull($this->sqlStateOf(fn () => DB::update("UPDATE civil_import_audit_events SET event_type = 'REQUEST_QUEUED' WHERE id = ?", [$id])));
        $this->assertNotNull($this->sqlStateOf(fn () => DB::delete('DELETE FROM civil_import_audit_events WHERE id = ?', [$id])));
        $this->assertNotNull($this->sqlStateOf(fn () => DB::insert("INSERT INTO civil_import_audit_events (event_type, actor_side) VALUES ('NOT_A_TYPE', 'SYSTEM')")));
        $this->assertSame(1, CivilImportAuditEvent::count());
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
