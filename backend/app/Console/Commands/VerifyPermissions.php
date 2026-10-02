<?php

namespace App\Console\Commands;

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
            'has' => ['family-membership.update'],
            'lacks' => ['family-membership.end', 'person.national-id.update', 'person.national-id.view', 'assistance.approve', 'export.basic'],
        ],
        'ADMINISTRATOR' => [
            'has' => ['family-membership.end', 'person.national-id.update', 'person-mobile-trust.grant'],
            'lacks' => ['person.national-id.view'],
        ],
        'SUPER_ADMIN' => [
            'has' => ['family-membership.end', 'person.national-id.update', 'system-admin.access', 'import.upload', 'import.review', 'person-mobile-trust.grant'],
            // import.apply is gated: see the explicit check in handle().
            'lacks' => ['person.national-id.view'],
        ],
        // Family Portal identity (docs/06 §22b). Mobile trust is granted by
        // SUPER_ADMIN and ADMINISTRATOR only; the COORDINATOR role must not
        // hold assist before coordinator scope authorization exists (PWA-1H).
        'REVIEWER' => [
            'has' => [],
            'lacks' => ['person-mobile-trust.grant', 'person-mobile-trust.revoke', 'person-mobile-trust.assist'],
        ],
        'FAMILY_USER' => [
            'has' => ['family-portal.access'],
            'lacks' => ['person-mobile-trust.grant', 'person-mobile-trust.assist', 'coordinator-space.access'],
        ],
        'COORDINATOR' => [
            'has' => ['coordinator-space.access'],
            'lacks' => ['person-mobile-trust.assist', 'person-mobile-trust.grant', 'person-mobile-trust.revoke',
                'user-person-link.manage', 'coordinator-scope.manage', 'family.view', 'person.view'],
        ],
    ];

    /** The single gated permission and the only role that may ever hold it. */
    public const APPLY_PERMISSION = 'import.apply';

    public const APPLY_ROLE = 'SUPER_ADMIN';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $problems = [];
        $applyEnabled = config('import.apply_enabled') === true;

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

            $rows[] = [$roleName, count($actual), count($expected)];
        }

        // Apply is never granted to a user directly — only through the gated role.
        $directApplyGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', config('permission.column_names.permission_pivot_key') ?? 'permission_id')
            ->where('p.name', self::APPLY_PERMISSION)
            ->count();
        if ($directApplyGrants > 0) {
            $problems[] = 'CRITICAL: '.self::APPLY_PERMISSION.' is granted directly to a model; it may only come from the '.self::APPLY_ROLE.' role';
        }

        $this->line('Import Apply gate: '.($applyEnabled ? 'ENABLED ('.self::APPLY_ROLE.' only)' : 'DISABLED (no role holds '.self::APPLY_PERMISSION.')'));

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
