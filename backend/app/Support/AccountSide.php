<?php

namespace App\Support;

use App\Models\User;

/**
 * The single source of account-side classification (docs/06 §22b,
 * AUTH-ADR-063). An account is Staff-side or family-side, never both:
 *
 *   STAFF    one or more Staff roles and no family-side role
 *   FAMILY   FAMILY_USER, optionally with COORDINATOR, and no Staff role
 *   INVALID  a Staff role mixed with a family-side role, or COORDINATOR
 *            without FAMILY_USER
 *   NONE     neither (no role, or only roles outside both sets)
 *
 * It looks at ALL of the user's roles, so nothing depends on role order.
 * Classification only: holding a side grants no permission and no scope.
 */
final class AccountSide
{
    public const STAFF = 'STAFF';

    public const FAMILY = 'FAMILY';

    public const INVALID = 'INVALID';

    public const NONE = 'NONE';

    public const FAMILY_USER = 'FAMILY_USER';

    public const COORDINATOR = 'COORDINATOR';

    /** Family-side roles: FAMILY_USER and COORDINATOR live on one account. */
    public const FAMILY_SIDE_ROLES = [self::FAMILY_USER, self::COORDINATOR];

    public static function of(User $user): string
    {
        $roles = $user->getRoleNames()->all();
        $staff = array_intersect($roles, StaffRoles::ALL);
        $family = array_intersect($roles, self::FAMILY_SIDE_ROLES);

        return match (true) {
            $staff !== [] && $family !== [] => self::INVALID,
            $staff !== [] => self::STAFF,
            $family === [] => self::NONE,
            in_array(self::FAMILY_USER, $family, true) => self::FAMILY,
            // COORDINATOR alone: a coordinator is always a Family User first.
            default => self::INVALID,
        };
    }

    public static function isStaff(User $user): bool
    {
        return self::of($user) === self::STAFF;
    }

    public static function isFamily(User $user): bool
    {
        return self::of($user) === self::FAMILY;
    }

    /** Whether the account holds FAMILY_USER or COORDINATOR, valid or not. */
    public static function holdsFamilySideRole(User $user): bool
    {
        return array_intersect($user->getRoleNames()->all(), self::FAMILY_SIDE_ROLES) !== [];
    }

    /**
     * The Staff role of a Staff-side account, in the canonical StaffRoles
     * order — never "the first role". NULL for any other account.
     */
    public static function staffRole(User $user): ?string
    {
        if (! self::isStaff($user)) {
            return null;
        }
        $held = $user->getRoleNames()->all();
        foreach (StaffRoles::ALL as $role) {
            if (in_array($role, $held, true)) {
                return $role;
            }
        }

        return null;
    }
}
