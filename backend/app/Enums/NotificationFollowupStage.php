<?php

namespace App\Enums;

// ADR FB-NOTIFY-01 ADR-05/06: FIRST (NORMAL 72 h unviewed, IMPORTANT 24 h
// unacknowledged) and SECOND (IMPORTANT 72 h still unacknowledged). Both may
// be open at once (D12).
enum NotificationFollowupStage: string
{
    case FIRST = 'FIRST';
    case SECOND = 'SECOND';
}
