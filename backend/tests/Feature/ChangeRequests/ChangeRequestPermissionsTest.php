<?php

namespace Tests\Feature\ChangeRequests;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Change Request permissions after PWA-5a (docs/06 AUTH-ADR-087, AE-11): the
 * Staff review set for SUPER_ADMIN, ADMINISTRATOR and REVIEWER; the family
 * set plus change-request.cancel for FAMILY_USER; nothing for any other role.
 */
class ChangeRequestPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_SET = [
        'change-request.view', 'change-request.review', 'change-request.return', 'change-request.approve',
        'change-request.reject', 'change-request.apply', 'change-request.view-internal-notes',
    ];

    private const FAMILY_SET = [
        'change-request.create', 'change-request.update-own-draft', 'change-request.submit', 'change-request.resubmit',
        'change-request.cancel',
    ];

    /** @return list<string> the change-request.* permissions a role holds */
    private function changeRequestPermissions(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->getAllPermissions()->pluck('name')
            ->filter(fn (string $name) => str_starts_with($name, 'change-request.'))
            ->values()->all();
    }

    public function test_the_catalog_has_twelve_change_request_permissions_including_cancel(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing(
            [...self::STAFF_SET, ...self::FAMILY_SET],
            Permission::where('name', 'like', 'change-request.%')->pluck('name')->all(),
        );
    }

    public function test_exact_grants_per_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'REVIEWER'] as $role) {
            $this->assertEqualsCanonicalizing(self::STAFF_SET, $this->changeRequestPermissions($role), $role);
        }
        $this->assertEqualsCanonicalizing(self::FAMILY_SET, $this->changeRequestPermissions('FAMILY_USER'));
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'COORDINATOR'] as $role) {
            $this->assertSame([], $this->changeRequestPermissions($role), $role);
        }
    }

    public function test_family_users_never_hold_a_staff_change_request_permission_and_staff_never_a_family_one(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // change-request.view stays Staff-side: family reads use family.context.
        $this->assertSame([], array_values(array_intersect(self::STAFF_SET, $this->changeRequestPermissions('FAMILY_USER'))));
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'REVIEWER'] as $role) {
            $this->assertSame([], array_values(array_intersect(self::FAMILY_SET, $this->changeRequestPermissions($role))), $role);
        }
    }

    public function test_the_permission_verifier_passes_on_the_seeded_baseline(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('famboook:verify-permissions')->assertSuccessful();
    }
}
