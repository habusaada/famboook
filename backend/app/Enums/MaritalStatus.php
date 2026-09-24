<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §10 "marital_status" (V1). UNKNOWN is the
// default and is never inferred to be SINGLE.
enum MaritalStatus: string
{
    case SINGLE = 'SINGLE';
    case MARRIED = 'MARRIED';
    case DIVORCED = 'DIVORCED';
    case WIDOWED = 'WIDOWED';
    case UNKNOWN = 'UNKNOWN';
}
