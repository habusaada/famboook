<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §36a — how the assistance is provided.
// Independent of the assistance category (what it is for).
enum AssistanceType: string
{
    case IN_KIND = 'IN_KIND';
    case CASH = 'CASH';
    case SERVICE = 'SERVICE';
}
