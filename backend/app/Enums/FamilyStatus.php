<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §7 "status" — initial Family lifecycle values.
// Additional statuses require an approved business decision (docs/02 §7).
enum FamilyStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case ARCHIVED = 'ARCHIVED';
}
