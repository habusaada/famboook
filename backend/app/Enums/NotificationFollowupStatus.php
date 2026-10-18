<?php

namespace App\Enums;

enum NotificationFollowupStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
