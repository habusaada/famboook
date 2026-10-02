<?php

namespace App\Enums;

// docs/02 §45b — how a link was verified. V1 uses the activation system
// process only; STAFF is reserved.
enum UserPersonLinkVerificationMethod: string
{
    case SYSTEM_OTP_ACTIVATION = 'SYSTEM_OTP_ACTIVATION';
    case STAFF = 'STAFF';
}
