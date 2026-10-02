<?php

namespace App\Enums;

// docs/02 §45b — why an authentication identity was superseded.
enum AuthIdentitySupersedeReason: string
{
    case NATIONAL_ID_CORRECTED = 'NATIONAL_ID_CORRECTED';
    case KEY_ROTATION = 'KEY_ROTATION';
}
