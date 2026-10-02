<?php

namespace App\Enums;

// docs/05 §53b — why a User-Person Link ended. Codes, never free text.
enum UserPersonLinkEndReason: string
{
    // Recorded by RecordPersonDeathAction; not an administrative choice.
    case PERSON_DECEASED = 'PERSON_DECEASED';
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
    case IDENTITY_ERROR = 'IDENTITY_ERROR';
}
