<?php

namespace App\Enums;

// docs/02 §45b — auth_otp_challenges.purpose.
enum OtpPurpose: string
{
    case ACTIVATION = 'ACTIVATION';
    case PASSWORD_RESET = 'PASSWORD_RESET';
    // CONTACT_UPDATE (docs/11 FP-ADR-079): the PROPOSED number (no trust row)
    // and the head's CURRENT trusted mobile (a trust-bound second factor).
    case CONTACT_NEW_MOBILE = 'CONTACT_NEW_MOBILE';
    case CONTACT_OLD_MOBILE = 'CONTACT_OLD_MOBILE';

    /** A challenge that belongs to a CONTACT_UPDATE Change Request. */
    public function isContactUpdate(): bool
    {
        return $this === self::CONTACT_NEW_MOBILE || $this === self::CONTACT_OLD_MOBILE;
    }
}
