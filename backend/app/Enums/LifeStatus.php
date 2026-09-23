<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §10 "life_status" — initial values.
enum LifeStatus: string
{
    case ALIVE = 'ALIVE';
    case DECEASED = 'DECEASED';
    case UNKNOWN = 'UNKNOWN';
}
