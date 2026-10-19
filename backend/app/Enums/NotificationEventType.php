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

    /** MESSAGE, RECIPIENT or FOLLOWUP — the rows the event must reference (chk_nre_scope). */
    public function scope(): string
    {
        return match ($this) {
            self::VIEWED, self::ACKNOWLEDGED, self::ACKNOWLEDGED_LATE => 'RECIPIENT',
            self::FOLLOWUP_OPENED, self::CONTACT_RECORDED, self::FOLLOWUP_CLOSED, self::NOTE_CORRECTION => 'FOLLOWUP',
            default => 'MESSAGE',
        };
    }

    /**
     * Who may be recorded as acting (FB-NOTIFY-01B.2b.3). The worker's steps
     * and the follow-up opening are the system's; a cancellation and a
     * contact are a person's; viewing and acknowledgement are the family's
     * alone (ADR-05); a note correction is Staff's (chk_nre_correction_actor).
     *
     * @return list<WorkflowActorSide>
     */
    public function actorSides(): array
    {
        $senders = [WorkflowActorSide::STAFF, WorkflowActorSide::COORDINATOR];

        return match ($this) {
            self::QUEUED, self::REQUEUED, self::FOLLOWUP_CLOSED => [WorkflowActorSide::SYSTEM, ...$senders],
            self::PUBLISHING_STARTED, self::PUBLISHED, self::PUBLISH_FAILED, self::EXPIRED_ARCHIVED, self::FOLLOWUP_OPENED => [WorkflowActorSide::SYSTEM],
            self::CANCELLED, self::CONTACT_RECORDED => $senders,
            self::VIEWED, self::ACKNOWLEDGED, self::ACKNOWLEDGED_LATE => [WorkflowActorSide::FAMILY],
            self::NOTE_CORRECTION => [WorkflowActorSide::STAFF],
        };
    }

    /** May carry an internal note (chk_nre_internal_note: Staff / coordinator only). */
    public function allowsInternalNote(): bool
    {
        return in_array($this, [self::CONTACT_RECORDED, self::FOLLOWUP_CLOSED], true);
    }
}
