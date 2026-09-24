<?php

namespace App\Enums;

// How a nominee was selected; stored at nomination, never inferred later.
enum NominationSource: string
{
    case TARGETING = 'TARGETING';
    case MANUAL = 'MANUAL';
    case NEED = 'NEED';
}
