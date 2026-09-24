<?php

namespace App\Enums;

// V1 supported currencies for planned assistance item values. Not
// reference data: adding one is a code change (docs/02 §36b).
enum Currency: string
{
    case ILS = 'ILS';
    case USD = 'USD';
    case JOD = 'JOD';
    case EUR = 'EUR';
}
