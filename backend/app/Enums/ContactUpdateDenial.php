<?php

namespace App\Enums;

// docs/11 FP-ADR-079 — why a CONTACT_UPDATE was refused or closed. INTERNAL:
// recorded as a CONTACT_MOBILE_CHANGED security-event reason code; the
// browser gets a field error or a public code, never this reason.
enum ContactUpdateDenial: string
{
    // The household head's current password did not match.
    case WRONG_CURRENT_PASSWORD = 'WRONG_CURRENT_PASSWORD';

    // A code of this request can no longer be used (locked, superseded,
    // expired without a resend, or its trust is no longer current).
    case VERIFICATION_FAILED = 'VERIFICATION_FAILED';

    // The registry changed since the request (base fingerprint), or the
    // member is no longer an active, living member.
    case NO_LONGER_APPLICABLE = 'NO_LONGER_APPLICABLE';

    // The request's verification window passed.
    case EXPIRED = 'EXPIRED';

    // A newer request for the same member replaced it.
    case SUPERSEDED = 'SUPERSEDED';
}
