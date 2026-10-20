<?php

namespace App\Enums;

// The reason code of a CONTACT_RECORDED follow-up event (ADR FB-NOTIFY-01
// ADR-06 §2c): what a Coordinator or Staff contact attempt achieved. Recording
// a contact NEVER closes the follow-up and NEVER counts as a family view or
// acknowledgement — those are recorded only by the family's own household
// head (FAMILY side). CALLED_INFORMED means the family was told, not that it
// acknowledged. Codes only; any free text goes to the internal note.
enum NotificationFollowupContactOutcome: string
{
    case CALLED_INFORMED = 'CALLED_INFORMED';
    case CALLED_NO_ANSWER = 'CALLED_NO_ANSWER';
    case VISITED = 'VISITED';
    case MESSAGE_LEFT = 'MESSAGE_LEFT';
    case OTHER = 'OTHER';
}
