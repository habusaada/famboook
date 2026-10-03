<?php

namespace App\Enums;

// docs/02 §45b — why a coordinator scope assignment (or the role) was
// revoked. Stored in coordinator_scope_assignments.revoke_reason.
enum CoordinatorRevokeReason: string
{
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
    case NO_LONGER_ELIGIBLE = 'NO_LONGER_ELIGIBLE';
    case SCOPE_CHANGED = 'SCOPE_CHANGED';
    // Set by RevokeCoordinatorRoleAction on every active assignment; never
    // chosen by a caller.
    case ROLE_REMOVED = 'ROLE_REMOVED';

    /** The reasons an administrator may choose. */
    public static function chosen(): array
    {
        return [self::ADMINISTRATIVE, self::NO_LONGER_ELIGIBLE, self::SCOPE_CHANGED];
    }
}
