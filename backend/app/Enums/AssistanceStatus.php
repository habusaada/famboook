<?php

namespace App\Enums;

// docs/03-BUSINESS-RULES.md §47a. V1-A implements DRAFT → OPEN only;
// COMPLETED / CANCELLED exist for the schema and are reached in V1-B.
enum AssistanceStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
