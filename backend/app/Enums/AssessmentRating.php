<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §27b — V1 rating scale for one assessment
// domain. There is deliberately no NOT_ASSESSED case: a domain without a
// stored result is "not assessed". Arabic labels live in the frontend.
enum AssessmentRating: string
{
    case NONE = 'NONE';
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';
}
