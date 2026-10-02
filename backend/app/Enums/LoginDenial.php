<?php

namespace App\Enums;

// docs/11 §30a — why a Family login or password reset request was refused,
// beyond the access resolver's own reasons (FamilyAccessDenial). INTERNAL
// ONLY: recorded as a security-event reason code. The browser always gets
// the same generic answer.
enum LoginDenial: string
{
    // No ACTIVE authentication identity carries the typed National ID.
    case UNKNOWN_IDENTIFIER = 'UNKNOWN_IDENTIFIER';
    case WRONG_PASSWORD = 'WRONG_PASSWORD';
    // A failure ceiling of this identifier was reached.
    case THROTTLED = 'THROTTLED';
    // The request carried no session to authenticate.
    case SESSION_REQUIRED = 'SESSION_REQUIRED';
    // The identity found by fingerprint is not the linked Person's current one.
    case IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';
    // The challenge's account no longer exists as it was issued.
    case ACCOUNT_CHANGED = 'ACCOUNT_CHANGED';
}
