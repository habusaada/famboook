<?php

namespace App\Enums;

// ADR FB-NOTIFY-01 ADR-05: NORMAL is satisfied by viewing; IMPORTANT needs an
// explicit family acknowledgement (viewing alone never satisfies it).
enum NotificationLevel: string
{
    case NORMAL = 'NORMAL';
    case IMPORTANT = 'IMPORTANT';
}
