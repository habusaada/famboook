<?php

namespace App\Enums;

// notification_recipient_events types (FB-NOTIFY-01B.2a) in three scopes:
// message-level (no recipient), recipient-level (FAMILY actor only) and
// follow-up-level. The database CHECK chk_nre_scope enforces the same split.
enum NotificationEventType: string
{
    // Message-level.
    case QUEUED = 'QUEUED';
    case PUBLISHING_STARTED = 'PUBLISHING_STARTED';
    case PUBLISHED = 'PUBLISHED';
    case PUBLISH_FAILED = 'PUBLISH_FAILED';
    case REQUEUED = 'REQUEUED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED_ARCHIVED = 'EXPIRED_ARCHIVED';

    // Recipient-level: the family's own actions.
    case VIEWED = 'VIEWED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case ACKNOWLEDGED_LATE = 'ACKNOWLEDGED_LATE';

    // Follow-up-level.
    case FOLLOWUP_OPENED = 'FOLLOWUP_OPENED';
    case CONTACT_RECORDED = 'CONTACT_RECORDED';
    case FOLLOWUP_CLOSED = 'FOLLOWUP_CLOSED';
    case NOTE_CORRECTION = 'NOTE_CORRECTION';

    public function isFamilyAction(): bool
    {
        return in_array($this, [self::VIEWED, self::ACKNOWLEDGED, self::ACKNOWLEDGED_LATE], true);
    }
}
