<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AccountSide;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deployment check (docs/08 §7): the roles and permissions stored in the
 * database must equal the canonical RolePermissionSeeder baseline exactly.
 * Run after `db:seed --class=RolePermissionSeeder` on every deployment; a
 * non-zero exit stops the deployment. Prints only role and permission
 * names — never users.
 *
 * The Pilot Gate found a database seeded before a permission change still
 * granting DATA_ENTRY family-membership.end; this makes that drift loud.
 *
 * `import.apply` is gated (config import.apply_enabled, docs/08): while the
 * gate is closed no role may hold it; while open, exactly SUPER_ADMIN must.
 * In both modes no other role, and no user directly, may ever hold it.
 */
class VerifyPermissions extends Command
{
    protected $signature = 'famboook:verify-permissions';

    protected $description = 'Verify that database roles/permissions match the canonical RolePermissionSeeder baseline';

    /**
     * Boundaries the Pilot depends on, checked by name on top of the exact
     * comparison so a failure message is explicit.
     *
     * @var array<string, array{has: list<string>, lacks: list<string>}>
     */
    public const CRITICAL = [
        'DATA_ENTRY' => [
            'has' => ['family-membership.update', ...self::CIVIL_LOOKUP_BASIC],
            'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, 'family-membership.end', 'person.national-id.update', 'person.national-id.view', 'assistance.approve', 'export.basic',
                'family-card.issue', 'family-card.revoke', 'family-card.reissue', ...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT,
                ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP],
        ],
        // Notifications (AUTH-ADR-097): ADMINISTRATOR sends to selected families,
        // never to every ACTIVE family (send-all is SUPER_ADMIN only).
        'ADMINISTRATOR' => [
            'has' => ['family-membership.end', 'person.national-id.update', 'person-mobile-trust.grant', 'family-card.reissue',
                'announcement.view', 'announcement.create', 'announcement.send', ...self::FOLLOWUP,
                ...self::CIVIL_LOOKUP_BASIC, 'civil-lookup.view-full', 'civil-lookup.usage.view'],
            'lacks' => [...self::CIVIL_IMPORT, 'civil-lookup.manage', 'person.national-id.view', 'announcement.send-all', ...self::COORDINATOR_ANNOUNCEMENT, ...self::COORDINATOR_FOLLOWUP],
        ],
        'SUPER_ADMIN' => [
            'has' => ['family-membership.end', 'person.national-id.update', 'system-admin.access', 'import.upload', 'import.review', 'person-mobile-trust.grant', 'family-card.reissue',
                ...self::ANNOUNCEMENT, ...self::FOLLOWUP, ...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP],
            // import.apply is gated: see the explicit check in handle().
            'lacks' => ['person.national-id.view', ...self::COORDINATOR_ANNOUNCEMENT, ...self::COORDINATOR_FOLLOWUP],
        ],
        // ADR FB-NOTIFY-01 ADR-04: no follow-up access for SOCIAL_WORKER until a Staff assignment model exists.
        'SOCIAL_WORKER' => ['has' => self::CIVIL_LOOKUP_BASIC, 'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, ...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT, ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP]],
        'REPORTS_VIEWER' => ['has' => self::CIVIL_LOOKUP_BASIC, 'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, ...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT, ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP]],
        // Family Portal identity (docs/06 §22b). Mobile trust is granted by
        // SUPER_ADMIN and ADMINISTRATOR only; the COORDINATOR role must not
        // hold assist before coordinator scope authorization exists (PWA-1H).
        // The Change Request review workspace (AUTH-ADR-087): reviewers can
        // reject and return as well as approve.
        'REVIEWER' => [
            'has' => ['change-request.view', 'change-request.review', 'change-request.return', 'change-request.reject',
                'change-request.approve', 'change-request.apply', ...self::CIVIL_LOOKUP_BASIC],
            'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, 'person-mobile-trust.grant', 'person-mobile-trust.revoke', 'person-mobile-trust.assist',
                'family-card.issue', 'family-card.revoke', 'family-card.reissue', ...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT,
                ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP],
        ],
        'FAMILY_USER' => [
            'has' => ['family-portal.access'],
            // FB-CIVIL-02B: civil-lookup.search / .view only while the Family side is open (checked below).
            'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, 'person-mobile-trust.grant', 'person-mobile-trust.assist', 'coordinator-space.access', 'coordinator-family.view-summary',
                'coordinator-member.view', 'coordinator-member.view-sensitive',
                'coordinator-request.view', 'coordinator-request.submit', 'coordinator-request.resubmit', 'coordinator-request.cancel',
                'family-card.view', 'family-card.issue', 'family-card.revoke', 'family-card.reissue',
                // Family reads use family.context, never the Staff queue; a
                // family never reviews its own request (WF-INV-026).
                'change-request.view', 'change-request.review', 'change-request.return', 'change-request.approve',
                'change-request.reject', 'change-request.apply', 'change-request.view-internal-notes',
                // Notifications: an ordinary household head never sends (AUTH-ADR-097)
                // and never handles follow-ups (no acting for the family, ADR-05).
                ...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT, ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP],
        ],
        // A Coordinator is family-side and scope-bound (PWA-1H): summaries and
        // (CO-01C, AUTH-ADR-092) the members directory with its audited reveal
        // in its scope, and nothing of the Staff API, review, export or trust.
        'COORDINATOR' => [
            'has' => ['coordinator-space.access', 'coordinator-family.view-summary', 'coordinator-member.view', 'coordinator-member.view-sensitive',
                // CO-02.3b (AUTH-ADR-093): own requests in the scope.
                'coordinator-request.view', 'coordinator-request.submit', 'coordinator-request.resubmit', 'coordinator-request.cancel',
                // AUTH-ADR-097: own scope-bound announcements, never announcement.*;
                // follow-ups of the current scope (manage as primary owner only).
                ...self::COORDINATOR_ANNOUNCEMENT, ...self::COORDINATOR_FOLLOWUP],
            // FB-CIVIL-02B: civil-lookup.search / .view only while the Coordinator side is open (checked below).
            'lacks' => [...self::CIVIL_IMPORT, ...self::CIVIL_LOOKUP_PRIVILEGED, ...self::ANNOUNCEMENT, ...self::FOLLOWUP, 'person-mobile-trust.assist', 'person-mobile-trust.grant', 'person-mobile-trust.revoke',
                'user-person-link.manage', 'coordinator-scope.manage', 'coordinator-scope.view', 'family.view', 'family.update',
                'person.view', 'person.update', 'person.national-id.view', 'person.national-id.view-masked',
                'change-request.review', 'export.basic', 'report.view', 'health-record.view', 'system-admin.access',
                'family-card.view', 'family-card.issue', 'family-card.revoke', 'family-card.reissue',
                // COORDINATOR ≠ REVIEWER (AUTH-ADR-087); its own requests use
                // coordinator-request.* only, never change-request.* (AUTH-ADR-093).
                'change-request.view', 'change-request.create', 'change-request.submit', 'change-request.cancel',
                'change-request.approve', 'change-request.reject', 'change-request.apply'],
        ],
    ];

    /** Staff announcement permissions (AUTH-ADR-097): SUPER_ADMIN and ADMINISTRATOR; send-all SUPER_ADMIN only. */
    public const ANNOUNCEMENT = ['announcement.view', 'announcement.create', 'announcement.send', 'announcement.send-all'];

    /** Coordinator announcement permissions (AUTH-ADR-097): the COORDINATOR role only. */
    public const COORDINATOR_ANNOUNCEMENT = ['coordinator-announcement.view', 'coordinator-announcement.create', 'coordinator-announcement.send'];

    /** Central follow-up permissions (ADR-06 §2c): SUPER_ADMIN and ADMINISTRATOR. */
    public const FOLLOWUP = ['notification-followup.view', 'notification-followup.manage'];

    /** Coordinator follow-up permissions (ADR-06 §2c): the COORDINATOR role only. */
    public const COORDINATOR_FOLLOWUP = ['coordinator-notification-followup.view', 'coordinator-notification-followup.manage'];

    /**
     * Civil Registry Lookup (FB-CIVIL-02A, ADR §L-3): search and view for every
     * Staff role; full view and usage for SUPER_ADMIN and ADMINISTRATOR;
     * administration for SUPER_ADMIN; nothing for the family side (02B).
     */
    public const CIVIL_LOOKUP_BASIC = ['civil-lookup.search', 'civil-lookup.view'];

    public const CIVIL_LOOKUP_PRIVILEGED = ['civil-lookup.view-full', 'civil-lookup.usage.view', 'civil-lookup.manage'];

    public const CIVIL_LOOKUP = [...self::CIVIL_LOOKUP_BASIC, ...self::CIVIL_LOOKUP_PRIVILEGED];

    /** Civil identity import management (FB-CIVIL-01A-UI U1–U3, AUTH-ADR-098): SUPER_ADMIN only. */
    public const CIVIL_IMPORT = ['civil-import.view', 'civil-import.locations.view', 'civil-import.audit.view',
        'civil-import.stage', 'civil-import.validate', 'civil-import.publish.request'];

    /** U3 / D-UI-12a: confirming publication is gated (config civil_import.publish_enabled, closed by default; S-4). */
    public const PUBLISH_CONFIRM_PERMISSION = 'civil-import.publish.confirm';

    /** The former second-approver permission (D-UI-12), removed by migration; it must not come back. */
    public const OBSOLETE_PUBLISH_APPROVE_PERMISSION = 'civil-import.publish.approve';

    /** The single gated permission and the only role that may ever hold it. */
    public const APPLY_PERMISSION = 'import.apply';

    public const APPLY_ROLE = 'SUPER_ADMIN';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $problems = [];
        $applyEnabled = config('import.apply_enabled') === true;
        $publishEnabled = config('civil_import.publish_enabled') === true;

        $missingPermissions = array_diff(RolePermissionSeeder::PERMISSIONS, Permission::pluck('name')->all());
        foreach ($missingPermissions as $name) {
            $problems[] = "permission missing: {$name}";
        }

        $rows = [];
        foreach (RolePermissionSeeder::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role === null) {
                $problems[] = "role missing: {$roleName}";

                continue;
            }

            $expected = RolePermissionSeeder::rolePermissions($roleName);
            $actual = $role->permissions()->pluck('name')->all();
            foreach (array_diff($actual, $expected) as $extra) {
                $problems[] = "{$roleName} has unexpected permission: {$extra}";
            }
            foreach (array_diff($expected, $actual) as $missing) {
                $problems[] = "{$roleName} lacks expected permission: {$missing}";
            }

            foreach (self::CRITICAL[$roleName]['has'] ?? [] as $name) {
                if (! in_array($name, $actual, true)) {
                    $problems[] = "CRITICAL: {$roleName} must have {$name}";
                }
            }
            foreach (self::CRITICAL[$roleName]['lacks'] ?? [] as $name) {
                if (in_array($name, $actual, true)) {
                    $problems[] = "CRITICAL: {$roleName} must NOT have {$name}";
                }
            }

            $mayApply = $applyEnabled && $roleName === self::APPLY_ROLE;
            if ($mayApply && ! in_array(self::APPLY_PERMISSION, $actual, true)) {
                $problems[] = "CRITICAL: {$roleName} must have ".self::APPLY_PERMISSION.' (Apply gate is enabled)';
            }
            if (! $mayApply && in_array(self::APPLY_PERMISSION, $actual, true)) {
                $problems[] = "CRITICAL: {$roleName} must NOT have ".self::APPLY_PERMISSION.($applyEnabled ? '' : ' (Apply gate is disabled)');
            }

            // FB-CIVIL-02B: a family-side role holds lookup search / view only while its side's switch is open.
            if (isset(RolePermissionSeeder::CIVIL_LOOKUP_FAMILY_SIDE_PERMISSIONS[$roleName])) {
                [$switch, $sidePermissions] = RolePermissionSeeder::CIVIL_LOOKUP_FAMILY_SIDE_PERMISSIONS[$roleName];
                $sideOpen = config($switch) === true;
                foreach ($sidePermissions as $name) {
                    if ($sideOpen !== in_array($name, $actual, true)) {
                        $problems[] = "CRITICAL: {$roleName} must ".($sideOpen ? '' : 'NOT ')."have {$name} ({$switch} is ".($sideOpen ? 'open' : 'closed').')';
                    }
                }
            }

            // Publication confirmation: SUPER_ADMIN only, and only while the publication gate is open.
            $mayConfirm = $publishEnabled && $roleName === self::APPLY_ROLE;
            if ($mayConfirm && ! in_array(self::PUBLISH_CONFIRM_PERMISSION, $actual, true)) {
                $problems[] = "CRITICAL: {$roleName} must have ".self::PUBLISH_CONFIRM_PERMISSION.' (publication gate is enabled)';
            }
            if (! $mayConfirm && in_array(self::PUBLISH_CONFIRM_PERMISSION, $actual, true)) {
                $problems[] = "CRITICAL: {$roleName} must NOT have ".self::PUBLISH_CONFIRM_PERMISSION.($publishEnabled ? '' : ' (publication gate is disabled)');
            }

            $rows[] = [$roleName, count($actual), count($expected)];
        }

        // Publication permissions only ever come from the role (D-UI-12a): a direct grant would bypass the gate.
        $directPublicationGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', config('permission.column_names.permission_pivot_key') ?? 'permission_id')
            ->whereIn('p.name', ['civil-import.publish.request', self::PUBLISH_CONFIRM_PERMISSION, self::OBSOLETE_PUBLISH_APPROVE_PERMISSION])
            ->count();
        if ($directPublicationGrants > 0) {
            $problems[] = "CRITICAL: {$directPublicationGrants} civil import publication permission grant(s) made directly to a model; they may only come from the role";
        }
        // The second-approver permission is gone with the two-person rule (D-UI-12a).
        if (Permission::query()->where('name', self::OBSOLETE_PUBLISH_APPROVE_PERMISSION)->exists()) {
            $problems[] = 'CRITICAL: '.self::OBSOLETE_PUBLISH_APPROVE_PERMISSION.' still exists; run the migrations (2026_10_27_090000)';
        }

        // Apply is never granted to a user directly — only through the gated role.
        $directApplyGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', config('permission.column_names.permission_pivot_key') ?? 'permission_id')
            ->where('p.name', self::APPLY_PERMISSION)
            ->count();
        if ($directApplyGrants > 0) {
            $problems[] = 'CRITICAL: '.self::APPLY_PERMISSION.' is granted directly to a model; it may only come from the '.self::APPLY_ROLE.' role';
        }

        // Civil Registry Lookup permissions only ever come from roles (FB-CIVIL-02B, ADR 02B §B-3): the
        // individual grant is a GRANTED row, and a direct grant would bypass the side switches.
        $directLookupGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', config('permission.column_names.permission_pivot_key') ?? 'permission_id')
            ->whereIn('p.name', self::CIVIL_LOOKUP)
            ->count();
        if ($directLookupGrants > 0) {
            $problems[] = "CRITICAL: {$directLookupGrants} civil lookup permission grant(s) made directly to a model; they may only come from roles";
        }

        // Notification permissions only ever come from their roles (AUTH-ADR-097):
        // a direct grant — send-all above all — would bypass the role baseline.
        $directNotificationGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', config('permission.column_names.permission_pivot_key') ?? 'permission_id')
            ->whereIn('p.name', [...self::ANNOUNCEMENT, ...self::COORDINATOR_ANNOUNCEMENT, ...self::FOLLOWUP, ...self::COORDINATOR_FOLLOWUP])
            ->count();
        if ($directNotificationGrants > 0) {
            $problems[] = "CRITICAL: {$directNotificationGrants} notification permission grant(s) made directly to a model; they may only come from their roles";
        }

        // Account sides are disjoint (docs/06 §22b): no account mixes a Staff
        // role with a family-side role, and COORDINATOR never stands without
        // FAMILY_USER. Counts only — never a user.
        $invalidAccounts = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', AccountSide::FAMILY_SIDE_ROLES))
            ->with('roles')
            ->get()
            ->filter(fn (User $user) => AccountSide::of($user) === AccountSide::INVALID)
            ->count();
        if ($invalidAccounts > 0) {
            $problems[] = "CRITICAL: {$invalidAccounts} account(s) mix Staff-side and family-side roles, or hold COORDINATOR without FAMILY_USER";
        }

        $this->line('Import Apply gate: '.($applyEnabled ? 'ENABLED ('.self::APPLY_ROLE.' only)' : 'DISABLED (no role holds '.self::APPLY_PERMISSION.')'));
        $this->line('Civil import publication gate: '.($publishEnabled ? 'ENABLED ('.self::APPLY_ROLE.' only)' : 'DISABLED (no role holds '.self::PUBLISH_CONFIRM_PERMISSION.')'));

        $this->table(['Role', 'Permissions in DB', 'Expected'], $rows);

        if ($problems !== []) {
            foreach (array_unique($problems) as $problem) {
                $this->error($problem);
            }
            $this->error('Permission verification FAILED. Run `php artisan db:seed --class=RolePermissionSeeder --force` and re-check.');

            return self::FAILURE;
        }

        $this->info('Permission verification passed: roles match the canonical baseline.');

        return self::SUCCESS;
    }
}
