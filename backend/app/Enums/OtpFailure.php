<?php

namespace App\Enums;

// docs/11 §30a — why an OTP operation failed. INTERNAL ONLY: a public
// endpoint maps every one of these to the same generic answer.
enum OtpFailure: string
{
    case NOT_FOUND = 'NOT_FOUND';
    case PURPOSE_MISMATCH = 'PURPOSE_MISMATCH';
    case PERSON_MISMATCH = 'PERSON_MISMATCH';
    case USER_MISMATCH = 'USER_MISMATCH';
    case EXPIRED = 'EXPIRED';
    case LOCKED = 'LOCKED';
    case SUPERSEDED = 'SUPERSEDED';
    case CONSUMED = 'CONSUMED';
    case ALREADY_VERIFIED = 'ALREADY_VERIFIED';
    case CODE_MISMATCH = 'CODE_MISMATCH';
    // The challenge's mobile is no longer the Person's current trusted mobile.
    case TRUST_NOT_CURRENT = 'TRUST_NOT_CURRENT';
    case COOLDOWN = 'COOLDOWN';
    case SEND_LIMIT = 'SEND_LIMIT';
    case THROTTLED = 'THROTTLED';
    case NOT_VERIFIED = 'NOT_VERIFIED';
    case GRANT_EXPIRED = 'GRANT_EXPIRED';
    case DELIVERY_FAILED = 'DELIVERY_FAILED';
}
