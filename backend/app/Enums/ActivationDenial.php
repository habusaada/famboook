<?php

namespace App\Enums;

// docs/11 §30a — why a Family activation step was refused, beyond the access
// resolver's and the OTP service's own reasons. INTERNAL ONLY: recorded as a
// security-event reason code, never sent to a browser.
enum ActivationDenial: string
{
    // No Person carries the typed National ID.
    case NOT_FOUND = 'NOT_FOUND';
    // The Person already has an ACTIVE or SUSPENDED User-Person Link.
    case ALREADY_LINKED = 'ALREADY_LINKED';
    // The request carried no session to authenticate after activation.
    case SESSION_REQUIRED = 'SESSION_REQUIRED';
    // The challenge reference does not lead to an activation challenge.
    case CHALLENGE_UNUSABLE = 'CHALLENGE_UNUSABLE';
    // The link or the identity could not be established (already taken).
    case IDENTITY_REFUSED = 'IDENTITY_REFUSED';
}
