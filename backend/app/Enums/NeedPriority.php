<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §34 — operational prioritization of a Need.
// Deliberately NOT the Assessment rating scale (no CRITICAL).
enum NeedPriority: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    /** SQL ordering: most urgent first. */
    public static function orderSql(string $column = 'priority'): string
    {
        return "CASE {$column} WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END";
    }
}
