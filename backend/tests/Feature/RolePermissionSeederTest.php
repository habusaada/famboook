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
            'health-record.view',
            'health-record.close',
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
            'activity-log.view',
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

        // View Reference Data ✓ (docs/06 §56, §140, AUTH-ADR-045).
        $this->assertTrue($dataEntry->hasPermissionTo('reference-data.view'));
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

        // Health records: view only (docs/06 §40, AUTH-ADR-048).
        $this->assertTrue($socialWorker->hasPermissionTo('health-record.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('health-record.create'));
        $this->assertFalse($socialWorker->hasPermissionTo('health-record.update'));
        $this->assertFalse($socialWorker->hasPermissionTo('health-record.close'));
        $this->assertFalse($socialWorker->hasPermissionTo('case-note.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('confidential-note.view'));
        // Assessments are operational for SOCIAL_WORKER (AUTH-ADR-050),
        // but review/verify/approve stay unassigned.
        $this->assertTrue($socialWorker->hasPermissionTo('assessment.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('assessment.approve'));
        // Needs are operational for SOCIAL_WORKER (AUTH-ADR-051);
        // need.cancel stays unassigned.
        $this->assertTrue($socialWorker->hasPermissionTo('need.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('need.cancel'));
        // Assistance V1-A: view + nominate only (AUTH-ADR-052).
        $this->assertTrue($socialWorker->hasPermissionTo('assistance.view'));
        $this->assertFalse($socialWorker->hasPermissionTo('assistance.create'));
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

        // View Reference Data ✓ (docs/06 §56, §140, AUTH-ADR-045).
        $this->assertTrue($administrator->hasPermissionTo('reference-data.view'));
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

        // Correct Basic Person Data (docs/06 §44, AUTH-ADR-047).
        $this->assertTrue($superAdmin->hasPermissionTo('person.update'));

        // SUPER_ADMIN does NOT receive a blanket grant: unresolved sensitive
        // and group-level permissions remain unassigned even for this role.
        $this->assertFalse($superAdmin->hasPermissionTo('confidential-note.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('person.national-id.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('assessment.approve'));
        $this->assertFalse($superAdmin->hasPermissionTo('need.cancel'));
        // Delivery reversal is granted to SUPER_ADMIN in V1-B (AUTH-ADR-053).
        $this->assertTrue($superAdmin->hasPermissionTo('assistance.reverse'));
        $this->assertFalse($superAdmin->hasPermissionTo('document.verify'));
        $this->assertFalse($superAdmin->hasPermissionTo('residence.change'));
        $this->assertFalse($superAdmin->hasPermissionTo('import.apply'));
        $this->assertFalse($superAdmin->hasPermissionTo('export.identity-data'));
        $this->assertFalse($superAdmin->hasPermissionTo('export.health-data'));
        $this->assertFalse($superAdmin->hasPermissionTo('workflow-history.view'));
        $this->assertFalse($superAdmin->hasPermissionTo('audit.view-sensitive'));

        // SUPER_ADMIN holds far fewer than the full 129-permission catalog,
        // confirming it is not implemented as a blanket-grant role.
        $this->assertLessThan(Permission::count(), $superAdmin->getAllPermissions()->count());
        // 69 = 42 + residence.update (AUTH-ADR-046)
        //      + health-record.view/create/update/close (AUTH-ADR-048)
        //      + activity-log.view (AUTH-ADR-049)
        //      + assessment.view/create/update/complete (AUTH-ADR-050)
        //      + need.view/create/update/close (AUTH-ADR-051)
        //      + assistance.view/create/update/open/nominate (AUTH-ADR-052)
        //      + assistance.approve/deliver/complete/export/export-sensitive/reverse (AUTH-ADR-053)
        //      + clan.view/manage (AUTH-ADR-054).
        $this->assertSame(69, $superAdmin->getAllPermissions()->count());
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
        $this->assertFalse($familyUser->hasPermissionTo('health-record.view'));
        $this->assertFalse($familyUser->hasPermissionTo('confidential-note.view'));
    }

    public function test_residence_update_is_granted_to_roles_that_update_canonical_family(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §46 V1 Role Assignment, AUTH-ADR-046: residence.update
        // mirrors family.update. residence.change (moves) stays unassigned.
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertTrue($user->hasPermissionTo('residence.update'), $role);
            $this->assertFalse($user->hasPermissionTo('residence.change'), $role);
        }

        foreach (['REVIEWER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertFalse($user->hasPermissionTo('residence.update'), $role);
        }
    }

    public function test_person_update_is_granted_only_to_approved_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §44 V1 Role Assignment, AUTH-ADR-047.
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertTrue($user->hasPermissionTo('person.update'), $role);
        }

        foreach (['REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertFalse($user->hasPermissionTo('person.update'), $role);
        }
    }

    public function test_person_update_does_not_carry_national_id_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // National ID stays behind its own, still-unassigned permissions
        // (docs/06 §39); person.update never implies them.
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach (['person.national-id.view', 'person.national-id.view-masked', 'person.national-id.update'] as $permission) {
                $this->assertFalse($user->hasPermissionTo($permission), "{$role} / {$permission}");
            }
        }
    }

    public function test_health_record_permissions_follow_approved_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §40 V1 Role Assignment, AUTH-ADR-048.
        $all = ['health-record.view', 'health-record.create', 'health-record.update', 'health-record.close'];
        $expected = [
            'SUPER_ADMIN' => $all,
            'ADMINISTRATOR' => $all,
            'DATA_ENTRY' => $all,
            'REVIEWER' => ['health-record.view'],
            'SOCIAL_WORKER' => ['health-record.view'],
            'REPORTS_VIEWER' => [],
            'FAMILY_USER' => [],
        ];

        foreach ($expected as $role => $granted) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach ($all as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->hasPermissionTo($permission),
                    "{$role} / {$permission}"
                );
            }
        }

        // The former health.* / disability.* names are gone from the catalog,
        // and person permissions never imply health access.
        foreach (['health.view', 'health.delete', 'disability.view', 'disability.delete'] as $old) {
            $this->assertFalse(Permission::where('name', $old)->exists(), $old);
        }
    }

    public function test_activity_log_view_follows_approved_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §57a V1 Role Assignment, AUTH-ADR-049.
        $expected = [
            'SUPER_ADMIN' => true,
            'ADMINISTRATOR' => true,
            'DATA_ENTRY' => true,
            'REVIEWER' => true,
            'SOCIAL_WORKER' => true,
            'REPORTS_VIEWER' => false,
            'FAMILY_USER' => false,
        ];

        foreach ($expected as $role => $granted) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertSame($granted, $user->hasPermissionTo('activity-log.view'), $role);
        }

        // Read-only by design: no activity write permissions exist, and the
        // activity log does not grant the restricted full audit.
        foreach (['activity-log.create', 'activity-log.update', 'activity-log.delete'] as $absent) {
            $this->assertFalse(Permission::where('name', $absent)->exists(), $absent);
        }
        $dataEntry = User::factory()->create();
        $dataEntry->assignRole('DATA_ENTRY');
        $this->assertFalse($dataEntry->hasPermissionTo('audit.view'));
    }

    public function test_assessment_permissions_follow_approved_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §47 V1 Role Assignment, AUTH-ADR-050.
        $all = ['assessment.view', 'assessment.create', 'assessment.update', 'assessment.complete'];
        $expected = [
            'SUPER_ADMIN' => $all,
            'ADMINISTRATOR' => $all,
            'DATA_ENTRY' => $all,
            'REVIEWER' => ['assessment.view'],
            'SOCIAL_WORKER' => $all,
            'REPORTS_VIEWER' => [],
            'FAMILY_USER' => [],
        ];

        foreach ($expected as $role => $granted) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach ($all as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->hasPermissionTo($permission),
                    "{$role} / {$permission}"
                );
            }

            // Review/verify/approve are not part of the V1 lifecycle.
            foreach (['assessment.review', 'assessment.verify', 'assessment.approve'] as $unassigned) {
                $this->assertFalse($user->hasPermissionTo($unassigned), "{$role} / {$unassigned}");
            }
        }

        // No assessment delete permission exists in V1.
        $this->assertFalse(Permission::where('name', 'assessment.delete')->exists());
    }

    public function test_need_permissions_follow_approved_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §49 V1 Role Assignment, AUTH-ADR-051. need.close governs
        // both fulfil and close; need.cancel is unassigned in V1.
        $all = ['need.view', 'need.create', 'need.update', 'need.close'];
        $expected = [
            'SUPER_ADMIN' => $all,
            'ADMINISTRATOR' => $all,
            'DATA_ENTRY' => $all,
            'REVIEWER' => ['need.view'],
            'SOCIAL_WORKER' => $all,
            'REPORTS_VIEWER' => [],
            'FAMILY_USER' => [],
        ];

        foreach ($expected as $role => $granted) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach ($all as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->hasPermissionTo($permission),
                    "{$role} / {$permission}"
                );
            }
            $this->assertFalse($user->hasPermissionTo('need.cancel'), "{$role} / need.cancel");
        }

        // No overlapping resolve permission and no delete permission in V1.
        $this->assertFalse(Permission::where('name', 'need.resolve')->exists());
        $this->assertFalse(Permission::where('name', 'need.delete')->exists());
    }

    public function test_assistance_permissions_follow_approved_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // docs/06 §50 V1-A Role Assignment, AUTH-ADR-052.
        $all = ['assistance.view', 'assistance.create', 'assistance.update', 'assistance.open', 'assistance.nominate'];
        $expected = [
            'SUPER_ADMIN' => $all,
            'ADMINISTRATOR' => $all,
            'DATA_ENTRY' => ['assistance.view', 'assistance.create', 'assistance.update', 'assistance.nominate'],
            'REVIEWER' => ['assistance.view'],
            'SOCIAL_WORKER' => ['assistance.view', 'assistance.nominate'],
            'REPORTS_VIEWER' => [],
            'FAMILY_USER' => [],
        ];

        foreach ($expected as $role => $granted) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach ($all as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->hasPermissionTo($permission),
                    "{$role} / {$permission}"
                );
            }
            // Delivery reversal is admin-only (AUTH-ADR-053).
            $this->assertSame(
                in_array($role, ['SUPER_ADMIN', 'ADMINISTRATOR'], true),
                $user->hasPermissionTo('assistance.reverse'),
                "{$role} / assistance.reverse"
            );
        }

        $this->assertFalse(Permission::where('name', 'assistance.delete')->exists());
    }
}
