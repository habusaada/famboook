<?php

namespace App\Enums;

// How a publication failure may be retried (FB-NOTIFY-01B.2b.5b; R4 as
// decided). NotificationFailureCode::category() is the only mapping.
enum NotificationFailureCategory: string
{
    // Never retried: retrying cannot succeed on this message. Only cancel.
    case PERMANENT = 'PERMANENT';

    // Never retried automatically; the sender may use their manual retry.
    case MANUAL_ONLY = 'MANUAL_ONLY';

    // Retried automatically within the automatic attempt budget, then once
    // manually.
    case TRANSIENT = 'TRANSIENT';
}
