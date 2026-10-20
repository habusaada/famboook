<?php

namespace App\Enums;

// The reason code of a MANUAL follow-up closure (outcome CLOSED_MANUALLY,
// close_reason_code; ADR FB-NOTIFY-01 ADR-06 §2c). A manual closure ends the
// follow-up only: it never marks the recipient viewed or acknowledged — the
// family's own acknowledgement stays the only acknowledgement. FAMILY_INACTIVE
// is the explicit way to end a follow-up of a family that left ACTIVE.
enum NotificationFollowupCloseReason: string
{
    case INFORMED_BY_PHONE = 'INFORMED_BY_PHONE';
    case INFORMED_IN_PERSON = 'INFORMED_IN_PERSON';
    case FAMILY_UNREACHABLE = 'FAMILY_UNREACHABLE';
    case FAMILY_INACTIVE = 'FAMILY_INACTIVE';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
