<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\ManageStaffUsersAction;
use App\Console\Commands\VerifyPermissions;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PWA-1C role and permission foundation (docs/06 §22b, AUTH-ADR-063/064):
 * the COORDINATOR role and the ten PWA-1 permission names. Seeding grants
 * names only — no endpoint, Coordinator Space or scope authorization exists
 * yet, and the coordinator's assist permission is deliberately deferred.
 */
class FamilyIdentityPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const PWA1 = [
        'family-portal.access',
        'coordinator-space.access',
        'person-mobile-trust.view',
        'person-mobile-trust.assist',
        'person-mobile-trust.grant',
        'person-mobile-trust.revoke',
        'user-person-link.view',
        'user-person-link.manage',
        'coordinator-scope.view',
        'coordinator-scope.manage',
    ];

    private const STAFF_ADMIN = [
        'person-mobile-trust.view',
        'person-mobile-trust.assist',
        'person-mobile-trust.grant',
        'person-mobile-trust.revoke',
        'user-person-link.view',
        'user-person-link.manage',
        'coordinator-scope.view',
        'coordinator-scope.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return list<string> */
    private function held(string $role): array
    {
        return array_values(array_intersect(self::PWA1, Role::findByName($role, 'web')->permissions->pluck('name')->all()));
    }

    public function test_the_ten_pwa1_permissions_and_the_coordinator_role_exist(): void
    {
        foreach (self::PWA1 as $name) {
            $this->assertTrue(Permission::where('name', $name)->where('guard_name', 'web')->exists(), $name);
        }
        $this->assertCount(10, array_intersect(self::PWA1, RolePermissionSeeder::PERMISSIONS));
        $this->assertContains('COORDINATOR', RolePermissionSeeder::ROLES);
        $this->assertNotNull(Role::findByName('COORDINATOR', 'web'));
    }

    public function test_only_super_admin_and_administrator_administer_family_identity(): void
    {
        $this->assertEqualsCanonicalizing(self::STAFF_ADMIN, $this->held('SUPER_ADMIN'));
        $this->assertEqualsCanonicalizing(self::STAFF_ADMIN, $this->held('ADMINISTRATOR'));

        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->assertSame([], $this->held($role), $role);
        }
    }

    public function test_only_super_admin_and_administrator_may_grant_or_revoke_mobile_trust(): void
    {
        foreach (RolePermissionSeeder::ROLES as $role) {
            $expected = in_array($role, ['SUPER_ADMIN', 'ADMINISTRATOR'], true);
            foreach (['person-mobile-trust.grant', 'person-mobile-trust.revoke'] as $permission) {
                $this->assertSame($expected, Role::findByName($role, 'web')->hasPermissionTo($permission), "{$role} {$permission}");
            }
        }
    }

    public function test_family_user_gains_family_portal_access_only(): void
    {
        $this->assertSame(['family-portal.access'], $this->held('FAMILY_USER'));
    }

    public function test_coordinator_holds_only_the_space_and_family_summaries(): void
    {
        // The whole role, not just the PWA-1 names: nothing else at all
        // (PWA-1H added the scoped family summaries, AUTH-ADR-070).
        $this->assertEqualsCanonicalizing(
            ['coordinator-space.access', 'coordinator-family.view-summary'],
            Role::findByName('COORDINATOR', 'web')->permissions->pluck('name')->all(),
        );
        // No other role holds the summaries — no Staff role either.
        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertSame($role === 'COORDINATOR', Role::findByName($role, 'web')->hasPermissionTo('coordinator-family.view-summary'), $role);
        }
    }

    public function test_coordinator_assist_is_deliberately_deferred_until_scope_enforcement(): void
    {
        $coordinator = Role::findByName('COORDINATOR', 'web');

        // Staged permission activation (AUTH-ADR-064): the name exists and
        // Staff administrators hold it; COORDINATOR receives it in PWA-1H.
        $this->assertTrue(Permission::where('name', 'person-mobile-trust.assist')->exists());
        $this->assertFalse($coordinator->hasPermissionTo('person-mobile-trust.assist'));
        $this->assertFalse($coordinator->hasPermissionTo('person-mobile-trust.grant'));
        $this->assertFalse($coordinator->hasPermissionTo('person-mobile-trust.revoke'));
        $this->assertFalse(Role::findByName('REVIEWER', 'web')->hasPermissionTo('person-mobile-trust.grant'));
    }

    public function test_one_family_side_account_may_hold_family_user_and_coordinator(): void
    {
        $user = User::factory()->familySide()->create();
        $user->assignRole(['FAMILY_USER', 'COORDINATOR']);
        $permissions = $user->getAllPermissions()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['FAMILY_USER', 'COORDINATOR'], $user->getRoleNames()->all());
        $this->assertContains('family-portal.access', $permissions);
        $this->assertContains('coordinator-space.access', $permissions);
        // Still no Staff capability and no trust capability of any kind.
        foreach (['family.view', 'person.view', 'person-mobile-trust.assist', 'person-mobile-trust.grant',
            'user-person-link.manage', 'coordinator-scope.manage', 'system-admin.access', 'dashboard.view-operational'] as $name) {
            $this->assertNotContains($name, $permissions, $name);
        }
    }

    public function test_coordinator_is_not_a_staff_role_and_cannot_be_assigned_from_staff_administration(): void
    {
        $this->assertNotContains('COORDINATOR', StaffRoles::ALL);
        $this->assertFalse(StaffRoles::isStaff('COORDINATOR'));

        $super = User::factory()->create();
        $super->assignRole('SUPER_ADMIN');
        $this->assertNotContains('COORDINATOR', ManageStaffUsersAction::assignableRoles($super));
    }

    public function test_the_permission_verifier_accepts_the_seeded_baseline(): void
    {
        $this->artisan('famboook:verify-permissions')->assertExitCode(0);

        $this->assertContains('person-mobile-trust.assist', VerifyPermissions::CRITICAL['COORDINATOR']['lacks']);
        $this->assertContains('person-mobile-trust.grant', VerifyPermissions::CRITICAL['COORDINATOR']['lacks']);
        $this->assertContains('person-mobile-trust.grant', VerifyPermissions::CRITICAL['REVIEWER']['lacks']);
    }

    public function test_the_permission_verifier_refuses_an_early_coordinator_or_reviewer_grant(): void
    {
        foreach ([
            ['COORDINATOR', 'person-mobile-trust.assist'],
            ['COORDINATOR', 'person-mobile-trust.grant'],
            ['REVIEWER', 'person-mobile-trust.grant'],
        ] as [$role, $permission]) {
            Role::findByName($role, 'web')->givePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->artisan('famboook:verify-permissions')
                ->expectsOutputToContain("CRITICAL: {$role} must NOT have {$permission}")
                ->assertExitCode(1);

            // Re-seeding (every deployment) restores the baseline.
            $this->seed(RolePermissionSeeder::class);
            $this->assertFalse(Role::findByName($role, 'web')->hasPermissionTo($permission));
        }
    }
}
