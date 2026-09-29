<?php

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
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
            'has' => ['family-membership.end', 'person.national-id.update'],
            'lacks' => ['person.national-id.view'],
        ],
        'SUPER_ADMIN' => [
            'has' => ['family-membership.end', 'person.national-id.update', 'system-admin.access', 'import.upload', 'import.review'],
            // import.apply is not assigned until the Apply phase (AUTH-ADR-060).
            'lacks' => ['person.national-id.view', 'import.apply'],
        ],
    ];

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $problems = [];

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

            $expected = RolePermissionSeeder::ROLE_PERMISSIONS[$roleName];
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

            $rows[] = [$roleName, count($actual), count($expected)];
        }

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
