<?php

namespace App\Enums;

// docs/03-BUSINESS-RULES.md §40a — V1 lifecycle: DRAFT → COMPLETED only.
// No reopening, review or approval states in V1.
enum AssessmentStatus: string
{
    case DRAFT = 'DRAFT';
    case COMPLETED = 'COMPLETED';
}
