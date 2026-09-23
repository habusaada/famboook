<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_ROLES = [
        'SUPER_ADMIN',
        'ADMINISTRATOR',
        'DATA_ENTRY',
        'REVIEWER',
        'SOCIAL_WORKER',
        'REPORTS_VIEWER',
        'FAMILY_USER',
    ];

    public function test_canonical_roles_can_be_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (self::CANONICAL_ROLES as $roleName) {
            $this->assertTrue(
                Role::where('name', $roleName)->exists(),
                "Expected canonical role [{$roleName}] to exist after seeding."
            );
        }

        $this->assertSame(count(self::CANONICAL_ROLES), Role::count());
    }

    public function test_canonical_permissions_can_be_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::count() > 0);

        // Spot-check a representative permission from each documented section.
        $expected = [
            'family.view',
            'person.record-death',
            'family-membership.transfer',
            'residence.change',
            'health.view',
            'disability.view',
            'confidential-note.view',
            'assessment.approve',
            'need.close',
            'assistance.reverse',
            'document.verify',
            'change-request.apply',
            'user.suspend',
            'role.assign',
            'reference-data.deactivate',
            'audit.view-sensitive',
            'workflow-history.view',
            'dashboard.view-executive',
            'export.sensitive',
            'import.apply',
            'system.jobs.manage',
            'system-admin.access',
        ];

        foreach ($expected as $permissionName) {
            $this->assertTrue(
                Permission::where('name', $permissionName)->exists(),
                "Expected documented permission [{$permissionName}] to exist after seeding."
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $roleCountAfterFirstRun = Role::count();
        $permissionCountAfterFirstRun = Permission::count();
        $superAdminPermissionCount = Role::where('name', 'SUPER_ADMIN')->first()->permissions()->count();

        // Run again.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($roleCountAfterFirstRun, Role::count());
        $this->assertSame($permissionCountAfterFirstRun, Permission::count());
        $this->assertSame(
            $superAdminPermissionCount,
            Role::where('name', 'SUPER_ADMIN')->first()->permissions()->count()
        );
    }

    public function test_data_entry_can_view_family_and_person_and_update_family(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $dataEntry = User::factory()->create();
        $dataEntry->assignRole('DATA_ENTRY');

        $this->assertTrue($dataEntry->hasPermissionTo('family.view'));
        $this->assertTrue($dataEntry->hasPermissionTo('person.view'));
        $this->assertTrue($dataEntry->hasPermissionTo('family.update'));
        $this->assertTrue($dataEntry->hasPermissionTo('family.create'));
        $this->assertTrue($dataEntry->hasPermissionTo('person.create'));
    }

    public function test_data_entry_cannot_approve_or_apply_change_requests(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $dataEntry = User::factory()->create();
        $dataEntry->assignRole('DATA_ENTRY');

        $this->assertFalse($dataEntry->hasPermissionTo('change-request.approve'));
        $this->assertFalse($dataEntry->hasPermissionTo('change-request.apply'));
        $this->assertFalse($dataEntry->hasPermissionTo('change-request.review'));
    }

    public function test_reviewer_can_view_family_and_person_and_process_change_requests(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reviewer = User::factory()->create();
        $reviewer->assignRole('REVIEWER');

        $this->assertTrue($reviewer->hasPermissionTo('family.view'));
        $this->assertTrue($reviewer->hasPermissionTo('person.view'));
        $this->assertTrue($reviewer->hasPermissionTo('change-request.review'));
        $this->assertTrue($reviewer->hasPermissionTo('change-request.approve'));
        $this->assertTrue($reviewer->hasPermissionTo('change-request.apply'));
    }

    public function test_reviewer_cannot_update_family(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reviewer = User::factory()->create();
        $reviewer->assignRole('REVIEWER');

        $this->assertFalse($reviewer->hasPermissionTo('family.update'));
    }

    public function test_social_worker_can_view_and_update_family_and_view_person(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $socialWorker = User::factory()->create();
        $socialWorker->assignRole('SOCIAL_WORKER');

        $this->assertTrue($socialWorker->hasPermissionTo('family.view'));
        $this->assertTrue($socialWorker->hasPermissionTo('person.view'));
        $this->assertTrue($socialWorker->hasPermissionTo('family.update'));
    }

    public function test_social_worker_does_not_automatically_receive_unresolved_sensitive_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $socialWorker = User::factory()->create();
        $socialWorker->assignRole('SOCIAL_WORKER');

        $this->assertFalse($socialWorker->hasPermissionTo('health.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('health.create'));
        $this->assertFalse($socialWorker->hasPermissionTo('disability.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('case-note.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('confidential-note.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('assessment.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('need.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('assistance.view'));
    }

    public function test_reports_viewer_can_view_family_person_dashboard_and_export_basic(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reportsViewer = User::factory()->create();
        $reportsViewer->assignRole('REPORTS_VIEWER');

        $this->assertTrue($reportsViewer->hasPermissionTo('family.view'));
        $this->assertTrue($reportsViewer->hasPermissionTo('person.view'));
        $this->assertTrue($reportsViewer->hasPermissionTo('dashboard.view-executive'));
        $this->assertTrue($reportsViewer->hasPermissionTo('export.basic'));
    }

    public function test_reports_viewer_cannot_export_sensitive(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reportsViewer = User::factory()->create();
        $reportsViewer->assignRole('REPORTS_VIEWER');

        $this->assertFalse($reportsViewer->hasPermissionTo('export.sensitive'));
    }

    public function test_administrator_receives_newly_documented_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $administrator = User::factory()->create();
        $administrator->assignRole('ADMINISTRATOR');

        // Direct-operation permissions (§96, §97, §98).
        $this->assertTrue($administrator->hasPermissionTo('family.change-household-head'));
        $this->assertTrue($administrator->hasPermissionTo('family-membership.transfer'));
        $this->assertTrue($administrator->hasPermissionTo('person.record-death'));

        // Change Request permissions (§66 worked example + §140 ✓).
        $this->assertTrue($administrator->hasPermissionTo('change-request.review'));
        $this->assertTrue($administrator->hasPermissionTo('change-request.approve'));
        $this->assertTrue($administrator->hasPermissionTo('change-request.apply'));

        // Audit.
        $this->assertTrue($administrator->hasPermissionTo('audit.view'));

        // Dashboard.
        $this->assertTrue($administrator->hasPermissionTo('dashboard.view-executive'));

        // Export.
        $this->assertTrue($administrator->hasPermissionTo('export.basic'));
        $this->assertTrue($administrator->hasPermissionTo('export.sensitive'));

        // Still no role/permission administration or Filament access — those
        // remain restricted to SUPER_ADMIN per §55 and are unresolved for
        // ADMINISTRATOR per the RBAC review.
        $this->assertFalse($administrator->hasPermissionTo('role.assign'));
        $this->assertFalse($administrator->hasPermissionTo('permission.assign'));
        $this->assertFalse($administrator->hasPermissionTo('system-admin.access'));
        $this->assertFalse($administrator->hasPermissionTo('user.suspend'));
    }

    public function test_super_admin_receives_newly_documented_permissions_but_not_unresolved_sensitive_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('SUPER_ADMIN');

        // Newly corrected direct-operation, review, audit, dashboard, export permissions.
        $this->assertTrue($superAdmin->hasPermissionTo('family.change-household-head'));
        $this->assertTrue($superAdmin->hasPermissionTo('family-membership.transfer'));
        $this->assertTrue($superAdmin->hasPermissionTo('person.record-death'));
        $this->assertTrue($superAdmin->hasPermissionTo('change-request.review'));
        $this->assertTrue($superAdmin->hasPermissionTo('change-request.approve'));
        $this->assertTrue($superAdmin->hasPermissionTo('change-request.apply'));
        $this->assertTrue($superAdmin->hasPermissionTo('dashboard.view-executive'));
        $this->assertTrue($superAdmin->hasPermissionTo('export.basic'));
        $this->assertTrue($superAdmin->hasPermissionTo('export.sensitive'));

        // Pre-existing admin-tier grants remain intact.
        $this->assertTrue($superAdmin->hasPermissionTo('role.assign'));
        $this->assertTrue($superAdmin->hasPermissionTo('system-admin.access'));
        $this->assertTrue($superAdmin->hasPermissionTo('audit.view'));

        // person.update: added for the Family Members Management slice
        // (no matrix row; explicitly reported, SUPER_ADMIN-only grant).
        $this->assertTrue($superAdmin->hasPermissionTo('person.update'));

        // SUPER_ADMIN does NOT receive a blanket grant: unresolved sensitive
        // and group-level permissions remain unassigned even for this role.
        $this->assertFalse($superAdmin->hasPermissionTo('health.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('disability.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('confidential-note.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('person.national-id.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('assessment.approve'));
        $this->assertFalse($superAdmin->hasPermissionTo('need.close'));
        $this->assertFalse($superAdmin->hasPermissionTo('assistance.reverse'));
        $this->assertFalse($superAdmin->hasPermissionTo('document.verify'));
        $this->assertFalse($superAdmin->hasPermissionTo('residence.change'));
        $this->assertFalse($superAdmin->hasPermissionTo('import.apply'));
        $this->assertFalse($superAdmin->hasPermissionTo('export.identity-data'));
        $this->assertFalse($superAdmin->hasPermissionTo('export.health-data'));
        $this->assertFalse($superAdmin->hasPermissionTo('workflow-history.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('audit.view-sensitive'));

        // SUPER_ADMIN holds far fewer than the full 123-permission catalog,
        // confirming it is not implemented as a blanket-grant role.
        $this->assertLessThan(Permission::count(), $superAdmin->getAllPermissions()->count());
        $this->assertSame(42, $superAdmin->getAllPermissions()->count());
    }

    public function test_documented_role_permission_assignments_work(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $familyUser = User::factory()->create();
        $familyUser->assignRole('FAMILY_USER');

        // §53: FAMILY_USER may receive these Change Request permissions.
        $this->assertTrue($familyUser->hasPermissionTo('change-request.create'));
        $this->assertTrue($familyUser->hasPermissionTo('change-request.update-own-draft'));
        $this->assertTrue($familyUser->hasPermissionTo('change-request.submit'));
        $this->assertTrue($familyUser->hasPermissionTo('change-request.resubmit'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('SUPER_ADMIN');

        $this->assertTrue($superAdmin->hasPermissionTo('role.assign'));
        $this->assertTrue($superAdmin->hasPermissionTo('user.suspend'));
        $this->assertTrue($superAdmin->hasPermissionTo('system-admin.access'));
        $this->assertTrue($superAdmin->hasPermissionTo('family.view'));
    }

    public function test_ordinary_user_does_not_receive_unauthorized_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $dataEntry = User::factory()->create();
        $dataEntry->assignRole('DATA_ENTRY');

        $this->assertFalse($dataEntry->hasPermissionTo('role.assign'));
        $this->assertFalse($dataEntry->hasPermissionTo('permission.assign'));
        $this->assertFalse($dataEntry->hasPermissionTo('family.change-household-head'));
        $this->assertFalse($dataEntry->hasPermissionTo('system-admin.access'));
        $this->assertFalse($dataEntry->hasPermissionTo('audit.view'));
        $this->assertFalse($dataEntry->hasPermissionTo('export.sensitive'));

        $unassignedUser = User::factory()->create();

        // A user with no role assigned has no permissions at all.
        $this->assertFalse($unassignedUser->hasPermissionTo('family.view'));
        $this->assertFalse($unassignedUser->hasPermissionTo('change-request.create'));
    }

    public function test_family_user_retains_only_change_request_permissions_and_no_staff_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $familyUser = User::factory()->create();
        $familyUser->assignRole('FAMILY_USER');

        // Retains exactly its approved Family Portal change-request permissions.
        $this->assertEqualsCanonicalizing(
            [
                'change-request.create',
                'change-request.update-own-draft',
                'change-request.submit',
                'change-request.resubmit',
            ],
            $familyUser->getAllPermissions()->pluck('name')->all()
        );

        // No staff permissions of any kind.
        $this->assertFalse($familyUser->hasPermissionTo('family.view'));
        $this->assertFalse($familyUser->hasPermissionTo('family.create'));
        $this->assertFalse($familyUser->hasPermissionTo('person.view'));
        $this->assertFalse($familyUser->hasPermissionTo('person.create'));
        $this->assertFalse($familyUser->hasPermissionTo('change-request.review'));
        $this->assertFalse($familyUser->hasPermissionTo('change-request.approve'));
        $this->assertFalse($familyUser->hasPermissionTo('change-request.reject'));
        $this->assertFalse($familyUser->hasPermissionTo('change-request.apply'));
        $this->assertFalse($familyUser->hasPermissionTo('change-request.view-internal-notes'));
        $this->assertFalse($familyUser->hasPermissionTo('role.assign'));
        $this->assertFalse($familyUser->hasPermissionTo('user.suspend'));
        $this->assertFalse($familyUser->hasPermissionTo('system-admin.access'));
        $this->assertFalse($familyUser->hasPermissionTo('audit.view'));
        $this->assertFalse($familyUser->hasPermissionTo('health.view'));
        $this->assertFalse($familyUser->hasPermissionTo('confidential-note.view'));
    }
}
