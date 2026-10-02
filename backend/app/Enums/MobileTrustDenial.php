<?php

namespace App\Enums;

// docs/11 §30a — why a Person has no usable trusted mobile. INTERNAL ONLY:
// a public endpoint never reveals which one applies.
enum MobileTrustDenial: string
{
    // No stored mobile, or one that is not a valid canonical number.
    case NO_VALID_MOBILE = 'NO_VALID_MOBILE';
    // A valid mobile that was never verified for this Person.
    case UNVERIFIED = 'UNVERIFIED';
    // The trust belongs to a number the Person no longer has (also after
    // changing BACK to it: a stale trust is never restored).
    case STALE = 'STALE';
    case REVOKED = 'REVOKED';
    // No usable Family Auth key: fail closed.
    case FINGERPRINT_UNAVAILABLE = 'FINGERPRINT_UNAVAILABLE';
}
