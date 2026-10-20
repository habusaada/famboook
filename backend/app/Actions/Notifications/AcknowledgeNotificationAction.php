<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Exceptions\NotificationInboxException;
use App\Models\NotificationRecipient;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\Notifications\FamilyNotificationRecipient;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationFollowupEngine;
use Illuminate\Support\Facades\DB;

/**
 * The household head explicitly acknowledges an IMPORTANT notification
 * (FB-NOTIFY-01B.2b.6b; ADR FB-NOTIFY-01 ADR-05, ADR-07). Never for a NORMAL
 * notification (NOTIFICATION_NOT_ACKNOWLEDGEABLE); never implicit — opening
 * the details is not an acknowledgement.
 *
 * Only the family's own household head in their CURRENT family context.
 * Under the recipient row lock, first write wins:
 * - acknowledging includes viewing: an unviewed recipient gets viewed_at and
 *   its VIEWED event first (chk_nr_ack_requires_view);
 * - acknowledged_at / acknowledged_by_user_id once, acknowledged_late when it
 *   happens at or after expires_at, and one ACKNOWLEDGED or
 *   ACKNOWLEDGED_LATE event — all in the same transaction;
 * - a repeat is a replay that writes nothing.
 * Late acknowledgement stays possible after expiry and archiving.
 *
 * Follow-ups (FB-NOTIFY-01E.3-F2): an acknowledgement before expires_at
 * closes every OPEN stage as SATISFIED — same transaction, same recipient
 * lock. A late one changes no follow-up (expiry wins; EXPIRED and other
 * CLOSED rows are never touched).
 */
class AcknowledgeNotificationAction
{
    public function __construct(
        private readonly NotificationEventRecorder $events,
        private readonly NotificationFollowupEngine $followups,
    ) {}

    /** @return array{0: NotificationRecipient, 1: bool} the recipient and whether this call recorded the acknowledgement */
    public function handle(FamilyAccessResult $context, string $messageUuid): array
    {
        $user = FamilyNotificationRecipient::actor($context);

        return DB::transaction(function () use ($context, $messageUuid, $user) {
            $recipient = FamilyNotificationRecipient::lock($context, $messageUuid);
            if ($recipient->message->level !== NotificationLevel::IMPORTANT) {
                throw new NotificationInboxException(NotificationInboxException::NOT_ACKNOWLEDGEABLE);
            }
            if ($recipient->acknowledged_at !== null) {
                return [$recipient, false];
            }

            // One timestamp: the view it implies, the acknowledgement, and lateness.
            $now = now();
            $viewed = $recipient->viewed_at === null;
            if ($viewed) {
                $recipient->forceFill(['viewed_at' => $now, 'viewed_by_user_id' => $user->getKey()]);
            }
            $expiresAt = $recipient->message->expires_at;
            $late = $expiresAt !== null && $now->getTimestamp() >= $expiresAt->getTimestamp();
            $recipient->forceFill([
                'acknowledged_at' => $now,
                'acknowledged_by_user_id' => $user->getKey(),
                'acknowledged_late' => $late,
            ])->save();

            if ($viewed) {
                $this->events->recipient($recipient, NotificationEventType::VIEWED, $user);
            }
            $this->events->recipient($recipient, $late ? NotificationEventType::ACKNOWLEDGED_LATE : NotificationEventType::ACKNOWLEDGED, $user);
            $this->followups->satisfyOnAcknowledgement($recipient, $recipient->message, $now);

            return [$recipient, true];
        });
    }
}
