<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// docs/06-PERMISSIONS.md §40-41, AUTH-ADR-048: health.* / disability.* were
// replaced by health-record.view/create/update/close. RolePermissionSeeder
// only ever adds permissions, so databases seeded earlier still hold the
// old rows. This removes exactly those eight names, and only if nothing
// uses them. Idempotent: rows already gone are skipped.
return new class extends Migration
{
    public const OBSOLETE_PERMISSIONS = [
        'health.view',
        'health.create',
        'health.update',
        'health.delete',
        'disability.view',
        'disability.create',
        'disability.update',
        'disability.delete',
    ];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $pivotKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        $ids = DB::table($tables['permissions'])
            ->whereIn('name', self::OBSOLETE_PERMISSIONS)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Never remove a permission that is still granted: stop instead.
        $assignedToRole = DB::table($tables['role_has_permissions'])->whereIn($pivotKey, $ids)->exists();
        $assignedToUser = DB::table($tables['model_has_permissions'])->whereIn($pivotKey, $ids)->exists();

        if ($assignedToRole || $assignedToUser) {
            throw new RuntimeException(
                'Obsolete health/disability permissions are still assigned to a role or user; '
                .'remove those assignments explicitly before running this migration.'
            );
        }

        DB::table($tables['permissions'])->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty: the removed names are superseded and must not
        // be recreated.
    }
};
