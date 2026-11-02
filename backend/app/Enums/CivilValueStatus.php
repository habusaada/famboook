<?php

namespace App\Enums;

// How a raw civil registry value was read by CivilRegistryValues (FB-FAMILY-ADD-01
// ADD-WP-05). Internal only; never sent to a client.
enum CivilValueStatus: string
{
    // An exactly listed value with a mapping.
    case MAPPED = 'MAPPED';
    // An exactly listed value that deliberately has no mapping (e.g. «غير معروف»).
    case INDETERMINATE = 'INDETERMINATE';
    // A value that is not on the list: never guessed.
    case UNSUPPORTED = 'UNSUPPORTED';
    // Empty or NULL.
    case MISSING = 'MISSING';
}
