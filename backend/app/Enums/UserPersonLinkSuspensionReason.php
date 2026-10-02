<?php

namespace App\Enums;

// docs/05 §53b — why a User-Person Link was suspended. Codes, never free text.
enum UserPersonLinkSuspensionReason: string
{
    case UNDER_INVESTIGATION = 'UNDER_INVESTIGATION';
    case REPORTED_COMPROMISE = 'REPORTED_COMPROMISE';
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
}
