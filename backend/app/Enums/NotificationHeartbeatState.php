<?php

namespace App\Enums;

// The notification worker heartbeat (FB-NOTIFY-01B.2b): only FRESH proves a
// scheduler dispatched it and a worker on the notifications queue ran it.
enum NotificationHeartbeatState: string
{
    case FRESH = 'FRESH';
    case STALE = 'STALE';
    case ABSENT = 'ABSENT';
}
