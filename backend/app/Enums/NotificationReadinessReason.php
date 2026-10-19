<?php

namespace App\Enums;

// Why notification work may not start now (FB-NOTIFY-01B.2b). Codes only:
// safe for logs and the health command, never shown to families.
enum NotificationReadinessReason: string
{
    case CONFIG_INVALID = 'CONFIG_INVALID';
    case MODE_OFF = 'MODE_OFF';
    case SENDER_NOT_IN_PILOT = 'SENDER_NOT_IN_PILOT';
    case QUEUE_NOT_ASYNC = 'QUEUE_NOT_ASYNC';
    case HEARTBEAT_ABSENT = 'HEARTBEAT_ABSENT';
    case HEARTBEAT_STALE = 'HEARTBEAT_STALE';
    case SYSTEM_DISCOVERY_REQUIRES_ON = 'SYSTEM_DISCOVERY_REQUIRES_ON';
    case SYSTEM_DISCOVERY_DISABLED = 'SYSTEM_DISCOVERY_DISABLED';
    case SYSTEM_CUTOFF_MISSING = 'SYSTEM_CUTOFF_MISSING';
    case SYSTEM_CUTOFF_INVALID = 'SYSTEM_CUTOFF_INVALID';
    case FOLLOWUPS_DISABLED = 'FOLLOWUPS_DISABLED';
}
