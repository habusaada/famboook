<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §10 "gender" — initial controlled values.
enum Gender: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
}
