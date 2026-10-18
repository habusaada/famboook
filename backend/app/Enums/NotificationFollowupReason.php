<?php

namespace App\Enums;

// Why a follow-up opened: a NORMAL message not viewed, or an IMPORTANT
// message not acknowledged.
enum NotificationFollowupReason: string
{
    case UNVIEWED = 'UNVIEWED';
    case UNACKNOWLEDGED = 'UNACKNOWLEDGED';
}
