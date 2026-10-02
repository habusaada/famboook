<?php

namespace App\Enums;

// docs/02 §45b — auth_otp_challenges.purpose.
enum OtpPurpose: string
{
    case ACTIVATION = 'ACTIVATION';
    case PASSWORD_RESET = 'PASSWORD_RESET';
}
