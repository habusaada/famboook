<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026_09_25_090002_remove_obsolete_health_permissions: removes exactly the
 * superseded health.* / disability.* rows, never anything else.
 */
class ObsoleteHealthPermissionsCleanupTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->migration = require database_path('migrations/2026_09_25_090002_remove_obsolete_health_permissions.php');
    }

    /** Simulates a database seeded before AUTH-ADR-048. */
    private function createObsoleteRows(): void
    {
        foreach ($this->migration::OBSOLETE_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /** @return list<string> */
    private function canonicalCatalog(): array
    {
        return (new \ReflectionClassConstant(RolePermissionSeeder::class, 'PERMISSIONS'))->getValue();
    }

    /** @return array<string, list<string>> */
    private function roleAssignments(): array
    {
        return Role::with('permissions')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
            ->all();
    }

    public function test_removes_exactly_the_obsolete_permissions(): void
    {
        $this->createObsoleteRows();
        $rolesBefore = $this->roleAssignments();
        $this->assertSame(120 + 8, Permission::count());

        $this->migration->up();

        foreach ($this->migration::OBSOLETE_PERMISSIONS as $name) {
            $this->assertFalse(Permission::where('name', $name)->exists(), $name);
        }
        $this->assertSame($rolesBefore, $this->roleAssignments());
    }

    public function test_db_catalog_matches_canonical_seeder_catalog(): void
    {
        $this->createObsoleteRows();
        $this->migration->up();

        $canonical = $this->canonicalCatalog();
        // 120 = 119 + activity-log.view (AUTH-ADR-049).
        $this->assertCount(120, $canonical);
        $this->assertSame(120, Permission::count());
        $this->assertEqualsCanonicalizing($canonical, Permission::pluck('name')->all());
    }

    public function test_is_idempotent(): void
    {
        $this->createObsoleteRows();

        $this->migration->up();
        $this->migration->up();
        $this->migration->up();

        $this->assertSame(120, Permission::count());
    }

    public function test_refuses_when_an_obsolete_permission_is_assigned_to_a_role(): void
    {
        $this->createObsoleteRows();
        Role::findByName('REVIEWER')->givePermissionTo('health.view');

        try {
            $this->migration->up();
            $this->fail('Expected the cleanup to refuse.');
        } catch (\RuntimeException) {
            // Nothing was deleted.
            $this->assertSame(120 + 8, Permission::count());
        }
    }

    public function test_refuses_when_an_obsolete_permission_is_assigned_to_a_user(): void
    {
        $this->createObsoleteRows();
        User::factory()->create()->givePermissionTo('disability.update');

        $this->expectException(\RuntimeException::class);

        $this->migration->up();
    }

    public function test_role_assignments_for_health_records_are_unchanged(): void
    {
        $this->createObsoleteRows();
        $this->migration->up();

        $all = ['health-record.close', 'health-record.create', 'health-record.update', 'health-record.view'];
        $expected = [
            'SUPER_ADMIN' => $all,
            'ADMINISTRATOR' => $all,
            'DATA_ENTRY' => $all,
            'REVIEWER' => ['health-record.view'],
            'SOCIAL_WORKER' => ['health-record.view'],
            'REPORTS_VIEWER' => [],
            'FAMILY_USER' => [],
        ];

        foreach ($expected as $role => $permissions) {
            $held = Role::findByName($role)->permissions->pluck('name')
                ->filter(fn ($n) => str_starts_with($n, 'health-record.'))->sort()->values()->all();
            $this->assertSame($permissions, $held, $role);
        }
    }
}
