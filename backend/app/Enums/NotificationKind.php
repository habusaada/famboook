<?php

namespace App\Enums;

// The internal kind of a notification message (ADR FB-NOTIFY-01 ADR-01):
// derived from an application event, or written by an authorized sender.
// A classification attribute, not a separate inbox.
enum NotificationKind: string
{
    case SYSTEM = 'SYSTEM';
    case ANNOUNCEMENT = 'ANNOUNCEMENT';
}
