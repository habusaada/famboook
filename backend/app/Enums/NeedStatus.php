<?php

namespace App\Enums;

// docs/03-BUSINESS-RULES.md §46a — V1 lifecycle: OPEN → FULFILLED | CLOSED.
// No reopening. IN_PROGRESS / PARTIALLY_FULFILLED belong to future
// Assistance tracking.
enum NeedStatus: string
{
    case OPEN = 'OPEN';
    case FULFILLED = 'FULFILLED';
    case CLOSED = 'CLOSED';
}
