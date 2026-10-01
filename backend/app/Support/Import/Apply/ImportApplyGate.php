<?php

namespace App\Support\Import\Apply;

use App\Models\User;

/**
 * The Apply activation gate (docs/03 §96b, docs/06 §61, docs/08). Apply is
 * allowed only while config('import.apply_enabled') is true AND the user
 * holds import.apply (SUPER_ADMIN only, via RolePermissionSeeder). The
 * runtime check means a stale or direct grant can never run Apply while the
 * gate is closed; it never widens authorization.
 */
final class ImportApplyGate
{
    public static function enabled(): bool
    {
        return config('import.apply_enabled') === true;
    }

    public static function allows(?User $user): bool
    {
        return self::enabled() && $user !== null && $user->can('import.apply');
    }
}
