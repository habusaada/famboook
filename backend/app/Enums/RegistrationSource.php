<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §7 "registration_source" — example values.
enum RegistrationSource: string
{
    case PAPER_FORM = 'PAPER_FORM';
    case MANUAL_ENTRY = 'MANUAL_ENTRY';
    case IMPORT = 'IMPORT';
    case VERIFIED_SOURCE = 'VERIFIED_SOURCE';
}
