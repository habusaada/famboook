<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the canonical RBAC baseline defined in docs/06-PERMISSIONS.md.
 *
 * Every permission below is traceable to an explicitly documented capability.
 *
 * Role-permission assignments follow docs/06-PERMISSIONS.md §140's Initial
 * Role Matrix. A cell marked "✓" or "Permission" is treated as a baseline
 * Spatie grant: §66 and §67 both describe a role holding a named permission
 * (e.g. change-request.approve) whose use is then further gated by Workflow
 * State, Data Scope, Object Access, Field Access, or Domain Rules — i.e. the
 * matrix's own footnote ("role membership alone is insufficient") describes
 * additional authorization layers on top of the grant, not withholding the
 * grant itself. Cells marked "Policy" are treated as case-by-case and are
 * NOT given a baseline grant, since no example in the document shows a role
 * routinely holding a permission whose only qualifier is "Policy".
 *
 * Capability-group rows (Needs/Assistance, Manage Users/Roles,
 * System Settings, etc.) that summarize multiple catalog permissions under
 * one matrix cell are deliberately left unassigned at the granular level:
 * the group-level intent may be clear, but which specific sub-permissions
 * apply is not decidable from the text alone. See the RBAC review conducted
 * 2026-09-22 for the full per-permission reasoning. The Assessments row was
 * resolved to granular V1 grants by AUTH-ADR-050 (§47); assessment.review/
 * verify/approve remain unassigned.
 *
 * SUPER_ADMIN is not given a blanket bypass and does not receive every
 * catalog permission. Its grants here are limited to what §140 and the
 * surrounding prose (§65, §96, §97, §98) directly support. SUPER_ADMIN
 * remains subject to Policies, Data Scope, Object Access, Field Access,
 * Workflow State, and Domain Rules once those layers are implemented
 * (§65: "even SUPER_ADMIN should not silently bypass" audit, transactions,
 * workflow history, database constraints, or domain invariants).
 *
 * Safe to run repeatedly: firstOrCreate() and syncPermissions() do not
 * create duplicates.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical roles, exactly as documented in docs/06-PERMISSIONS.md §14 / §61.
     */
    private const ROLES = [
        'SUPER_ADMIN',
        'ADMINISTRATOR',
        'DATA_ENTRY',
        'REVIEWER',
        'SOCIAL_WORKER',
        'REPORTS_VIEWER',
        'FAMILY_USER',
    ];

    /**
     * Canonical permission catalog, derived from docs/06-PERMISSIONS.md.
     * Grouped by the document section each permission set comes from.
     */
    private const PERMISSIONS = [
        // §43 Family Permissions
        'family.view',
        'family.create',
        'family.update',
        'family.archive',
        'family.restore',
        'family.change-household-head',
        'family.view-history',

        // §44 Person Permissions
        'person.view',
        'person.create',
        'person.update',
        'person.archive',
        'person.view-history',
        'person.record-death',
        'person.correct',

        // §39 National ID Permission
        'person.national-id.view',
        'person.national-id.view-masked',
        'person.national-id.update',

        // §45 Membership Permissions
        'family-membership.view',
        'family-membership.create',
        'family-membership.update',
        'family-membership.end',
        'family-membership.transfer',

        // §46 Residence Permissions
        'residence.view',
        'residence.create',
        'residence.update',
        'residence.change',
        'residence.view-history',

        // §40 Health Record Permissions (replaces the former health.* /
        // disability.* names — one permission family for the unified,
        // person-based health record; no delete in V1).
        'health-record.view',
        'health-record.create',
        'health-record.update',
        'health-record.close',

        // §42 Confidential Notes
        'case-note.view',
        'case-note.create',
        'case-note.update',
        'case-note.delete',
        'confidential-note.view',

        // §47 Assessment Permissions
        'assessment.view',
        'assessment.create',
        'assessment.update',
        'assessment.complete',
        'assessment.review',
        'assessment.verify',
        'assessment.approve',

        // §48 Form Permissions
        'form.view',
        'form.create',
        'form.update',
        'form.submit',
        'form.review',
        'form.return',
        'form.verify',
        'form.approve',

        // §49 Need Permissions
        'need.view',
        'need.create',
        'need.update',
        'need.close',
        'need.cancel',

        // §50 Assistance Permissions
        'assistance.view',
        'assistance.create',
        'assistance.update',
        'assistance.reverse',

        // §51 Document Permissions
        'document.view',
        'document.upload',
        'document.update',
        'document.verify',
        'document.reject',
        'document.download',
        'document.delete',

        // §52 Change Request Permissions
        'change-request.view',
        'change-request.create',
        'change-request.update-own-draft',
        'change-request.submit',
        'change-request.review',
        'change-request.return',
        'change-request.resubmit',
        'change-request.approve',
        'change-request.reject',
        'change-request.apply',
        'change-request.view-internal-notes',

        // §54 User Administration Permissions
        'user.view',
        'user.create',
        'user.update',
        'user.activate',
        'user.suspend',
        'user.reset-access',
        'user.link-person',
        'user.verify-person-link',

        // §55 Role Administration Permissions
        'role.view',
        'role.create',
        'role.update',
        'role.delete',
        'role.assign',
        'permission.view',
        'permission.assign',

        // §56 Reference Data Permissions
        'reference-data.view',
        'reference-data.create',
        'reference-data.update',
        'reference-data.deactivate',

        // §57 Audit Permissions
        'audit.view',
        'audit.view-sensitive',

        // §57a Family Activity Log (read-only; no create/update/delete
        // permissions exist — entries are written only by Domain Actions)
        'activity-log.view',

        // §58 Workflow History Permission
        'workflow-history.view',

        // §59 Report Permissions
        'report.view',
        'report.view-sensitive',
        'dashboard.view-operational',
        'dashboard.view-executive',

        // §60 Export Permissions
        'export.basic',
        'export.sensitive',
        'export.identity-data',
        'export.health-data',

        // §61 Import Permissions
        'import.upload',
        'import.validate',
        'import.review',
        'import.apply',

        // §62 System Administration Permissions
        'system.settings.view',
        'system.settings.update',
        'system.jobs.view',
        'system.jobs.manage',
        'system.health.view',
        'system.reference-data.manage',

        // §63 Filament Access
        'system-admin.access',
    ];

    /**
     * Role → permission assignments supported by docs/06-PERMISSIONS.md
     * §140's Initial Role Matrix ("✓" and "Permission" cells; see the class
     * docblock for why "Permission" is treated as a grant) plus directly
     * corroborating prose (§65, §96, §97, §98) and, for FAMILY_USER, the
     * explicit list in §53.
     *
     * Roles/capabilities not listed here are intentionally left with no
     * assigned permissions: either the matrix marks them "Policy" (case-by-
     * case, not a baseline grant), the row summarizes multiple catalog
     * permissions without specifying which apply, or the capability has no
     * matrix row / role-specific text at all. See the RBAC review conducted
     * 2026-09-22 for the full list of deliberately unresolved assignments.
     */
    private const ROLE_PERMISSIONS = [
        'SUPER_ADMIN' => [
            // Filament ✓
            'system-admin.access',
            // View/Create/Update Canonical Family ✓
            'family.view',
            'family.create',
            'family.update',
            // Correct Current Residence (§46 V1 Role Assignment, AUTH-ADR-046)
            'residence.update',
            // View/Create Person ✓
            'person.view',
            'person.create',
            // Correct Basic Person Data (§44 V1 Role Assignment, AUTH-ADR-047)
            'person.update',
            // Health Records ✓ (§40 V1 Role Assignment, AUTH-ADR-048)
            'health-record.view',
            'health-record.create',
            'health-record.update',
            'health-record.close',
            // Family Activity Log (§57a V1 Role Assignment, AUTH-ADR-049)
            'activity-log.view',
            // Assessments (§47 V1 Role Assignment, AUTH-ADR-050)
            'assessment.view',
            'assessment.create',
            'assessment.update',
            'assessment.complete',
            // Change Household Head: Permission (§97 names this permission directly)
            'family.change-household-head',
            // Transfer Membership: Permission (§98 names this permission directly)
            'family-membership.transfer',
            // Record Official Death: Permission (§96 names this permission directly)
            'person.record-death',
            // Review Change Request: Permission
            'change-request.review',
            // Approve Change Request: Permission (§66 worked example)
            'change-request.approve',
            // Apply Change Request: Permission
            'change-request.apply',
            // View Audit ✓
            'audit.view',
            // View Executive Dashboard: Permission
            'dashboard.view-executive',
            // Export Basic: Permission
            'export.basic',
            // Export Sensitive: Permission
            'export.sensitive',
            // Manage Users/Roles ✓ (role administration)
            'role.view',
            'role.create',
            'role.update',
            'role.delete',
            'role.assign',
            // Manage Users/Roles ✓ (permission administration)
            'permission.view',
            'permission.assign',
            // Manage Users/Roles ✓ (user administration)
            'user.view',
            'user.create',
            'user.update',
            'user.activate',
            'user.suspend',
            'user.reset-access',
            'user.link-person',
            'user.verify-person-link',
            // System Settings ✓ (reference data administration)
            'reference-data.view',
            'reference-data.create',
            'reference-data.update',
            'reference-data.deactivate',
            // System Settings ✓ (system configuration/maintenance)
            'system.settings.view',
            'system.settings.update',
            'system.jobs.view',
            'system.jobs.manage',
            'system.health.view',
            'system.reference-data.manage',
        ],
        'ADMINISTRATOR' => [
            // View/Create/Update Canonical Family ✓
            'family.view',
            'family.create',
            'family.update',
            // Correct Current Residence (§46 V1 Role Assignment, AUTH-ADR-046)
            'residence.update',
            // View/Create Person ✓
            'person.view',
            'person.create',
            // Correct Basic Person Data (§44 V1 Role Assignment, AUTH-ADR-047)
            'person.update',
            // Health Records ✓ (§40 V1 Role Assignment, AUTH-ADR-048)
            'health-record.view',
            'health-record.create',
            'health-record.update',
            'health-record.close',
            // Family Activity Log (§57a V1 Role Assignment, AUTH-ADR-049)
            'activity-log.view',
            // Assessments (§47 V1 Role Assignment, AUTH-ADR-050)
            'assessment.view',
            'assessment.create',
            'assessment.update',
            'assessment.complete',
            // Change Household Head: Permission (§97 names this permission directly)
            'family.change-household-head',
            // Transfer Membership: Permission (§98 names this permission directly)
            'family-membership.transfer',
            // Record Official Death: Permission (§96 names this permission directly)
            'person.record-death',
            // Review Change Request ✓
            'change-request.review',
            // Approve Change Request: Permission (§66 worked example)
            'change-request.approve',
            // Apply Change Request: Permission
            'change-request.apply',
            // View Audit: Permission
            'audit.view',
            // View Executive Dashboard: Permission
            'dashboard.view-executive',
            // Export Basic: Permission
            'export.basic',
            // Export Sensitive: Permission
            'export.sensitive',
            // View Reference Data ✓ (§56 V1 Role Assignment, AUTH-ADR-045)
            'reference-data.view',
        ],
        'DATA_ENTRY' => [
            // Create Family ✓
            'family.create',
            // Create Person ✓
            'person.create',
            // Correct Basic Person Data (§44 V1 Role Assignment, AUTH-ADR-047)
            'person.update',
            // Health Records ✓ (§40 V1 Role Assignment, AUTH-ADR-048)
            'health-record.view',
            'health-record.create',
            'health-record.update',
            'health-record.close',
            // View Family: Scope (grant + Data Scope constraint)
            'family.view',
            // View Person: Scope (grant + Data Scope constraint)
            'person.view',
            // Family Activity Log (§57a V1 Role Assignment, AUTH-ADR-049)
            'activity-log.view',
            // Assessments (§47 V1 Role Assignment, AUTH-ADR-050)
            'assessment.view',
            'assessment.create',
            'assessment.update',
            'assessment.complete',
            // Update Canonical Family: Draft/limited (grant + Workflow State constraint)
            'family.update',
            // Correct Current Residence (§46 V1 Role Assignment, AUTH-ADR-046)
            'residence.update',
            // View Reference Data ✓ (§56 V1 Role Assignment, AUTH-ADR-045)
            'reference-data.view',
        ],
        'REVIEWER' => [
            // Review Change Request ✓
            'change-request.review',
            // Approve Change Request: Permission (§66 worked example)
            'change-request.approve',
            // Apply Change Request: Permission
            'change-request.apply',
            // View Family: Scope (grant + Data Scope constraint)
            'family.view',
            // View Person: Scope (grant + Data Scope constraint)
            'person.view',
            // Health Records: view only (§40 V1 Role Assignment, AUTH-ADR-048)
            'health-record.view',
            // Family Activity Log (§57a V1 Role Assignment, AUTH-ADR-049)
            'activity-log.view',
            // Assessments: view only (§47 V1 Role Assignment, AUTH-ADR-050)
            'assessment.view',
        ],
        'SOCIAL_WORKER' => [
            // View Family: Scope (grant + Data Scope constraint)
            'family.view',
            // View Person: Scope (grant + Data Scope constraint)
            'person.view',
            // Health Records: view only (§40 V1 Role Assignment, AUTH-ADR-048)
            'health-record.view',
            // Family Activity Log (§57a V1 Role Assignment, AUTH-ADR-049)
            'activity-log.view',
            // Assessments (§47 V1 Role Assignment, AUTH-ADR-050)
            'assessment.view',
            'assessment.create',
            'assessment.update',
            'assessment.complete',
            // Update Canonical Family: Limited (grant + Field/Object Access constraint)
            'family.update',
            // Correct Current Residence (§46 V1 Role Assignment, AUTH-ADR-046)
            'residence.update',
        ],
        'REPORTS_VIEWER' => [
            // View Executive Dashboard ✓
            'dashboard.view-executive',
            // View Family: Report scope (grant + Data Scope constraint)
            'family.view',
            // View Person: Report scope (grant + Data Scope constraint)
            'person.view',
            // Export Basic: Permission
            'export.basic',
        ],
        'FAMILY_USER' => [
            // §53 Family User Change Request Permissions (explicit "may receive" list)
            // Also matches §140 Submit Change Request ✓
            'change-request.create',
            'change-request.update-own-draft',
            'change-request.submit',
            'change-request.resubmit',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::ROLES as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions(self::ROLE_PERMISSIONS[$roleName]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
