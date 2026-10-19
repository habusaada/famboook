<?php

namespace App\Enums;

// The notifications feature mode (FB-NOTIFY-01B.2b). Read only through
// NotificationConfig, which fails closed to OFF.
enum NotificationMode: string
{
    // Nothing is published: the default and the emergency state.
    case OFF = 'OFF';

    // Only the senders on the configured pilot allow-list.
    case PILOT = 'PILOT';

    // Every otherwise authorized sender.
    case ON = 'ON';
}
