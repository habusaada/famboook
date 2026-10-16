<?php

namespace App\Enums;

// Stage 1A finding severities; BLOCKER findings abort the import instead.
enum GazaLocationReviewSeverity: string
{
    case WARNING = 'WARNING';
    case INFO = 'INFO';
}
