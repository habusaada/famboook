<?php

namespace App\Enums;

// docs/11 §30a — why the Family access resolver denied. INTERNAL ONLY: never
// sent to a client, which sees the generic failure (or 401 / a neutral 403).
enum FamilyAccessDenial: string
{
    // Identity validity
    case USER_INACTIVE = 'USER_INACTIVE';
    case NOT_FAMILY_SIDE = 'NOT_FAMILY_SIDE';
    case NO_LINK = 'NO_LINK';
    case LINK_SUSPENDED = 'LINK_SUSPENDED';
    case PERSON_DELETED = 'PERSON_DELETED';
    case PERSON_INACTIVE = 'PERSON_INACTIVE';
    case PERSON_NOT_ALIVE = 'PERSON_NOT_ALIVE';
    case NO_AUTH_IDENTITY = 'NO_AUTH_IDENTITY';
    case AUTH_IDENTITY_SUSPENDED = 'AUTH_IDENTITY_SUSPENDED';
    case NATIONAL_ID_INVALID = 'NATIONAL_ID_INVALID';
    case IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';
    case FINGERPRINT_UNAVAILABLE = 'FINGERPRINT_UNAVAILABLE';

    // Family context
    case NO_ACTIVE_MEMBERSHIP = 'NO_ACTIVE_MEMBERSHIP';
    case NOT_HOUSEHOLD_HEAD = 'NOT_HOUSEHOLD_HEAD';
    case FAMILY_NOT_ACTIVE = 'FAMILY_NOT_ACTIVE';
    case FAMILY_DELETED = 'FAMILY_DELETED';

    /**
     * True when the identity itself is invalid (the session must end);
     * false when only the family context is missing.
     */
    public function isIdentity(): bool
    {
        return ! in_array($this, [
            self::NO_ACTIVE_MEMBERSHIP, self::NOT_HOUSEHOLD_HEAD, self::FAMILY_NOT_ACTIVE, self::FAMILY_DELETED,
        ], true);
    }
}
