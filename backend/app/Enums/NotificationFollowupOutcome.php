<?php

namespace App\Enums;

// How a follow-up closed (ADR FB-NOTIFY-01 ADR-06/07): by the family, by
// expiry (never reopened), or manually by an authorized user with a reason
// code — which never counts as a family view or acknowledgement.
enum NotificationFollowupOutcome: string
{
    case SATISFIED = 'SATISFIED';
    case EXPIRED = 'EXPIRED';
    case CLOSED_MANUALLY = 'CLOSED_MANUALLY';
}
