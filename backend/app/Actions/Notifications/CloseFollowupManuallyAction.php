<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupCloseReason;
use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupStatus;
use App\Exceptions\NotificationFollowupException;
use App\Models\NotificationFollowup;
use App\Models\User;
use App\Support\Notifications\FollowupManagement;
use App\Support\Notifications\NotificationEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Closes an OPEN follow-up manually (FB-NOTIFY-01E.3-F3.1; ADR FB-NOTIFY-01
 * ADR-06, §2c): CLOSED / CLOSED_MANUALLY with the closing user, a closure
 * reason code (NotificationFollowupCloseReason) and exactly one
 * FOLLOWUP_CLOSED event (optional internal note) — one transaction.
 *
 * Central Staff, or the family's CURRENT primary coordinator only — see
 * FollowupManagement (locks, re-checked authorization, expiry). The recipient
 * is never touched (a manual closure is no view and no acknowledgement); the
 * row is never reopened; closing FIRST never suppresses an IMPORTANT SECOND
 * (each stage is its own row and opens on its own deadline). A refusal
 * writes nothing.
 */
class CloseFollowupManuallyAction
{
    public function __construct(
        private readonly FollowupManagement $management,
        private readonly NotificationEventRecorder $events,
    ) {}

    public function handle(User $actor, string $followupUuid, NotificationFollowupCloseReason|string $reason, ?string $note = null): NotificationFollowup
    {
        $reason = $reason instanceof NotificationFollowupCloseReason ? $reason : NotificationFollowupCloseReason::tryFrom($reason)
            ?? throw new NotificationFollowupException(NotificationFollowupException::REASON_INVALID);
        $note = FollowupManagement::note($note);
        FollowupManagement::assertMayManageAny($actor);

        return DB::transaction(function () use ($actor, $followupUuid, $reason, $note) {
            ['followup' => $followup, 'actor' => $user, 'side' => $side, 'now' => $now] = $this->management->lockAndAuthorize($actor, $followupUuid);

            $followup->forceFill([
                'status' => NotificationFollowupStatus::CLOSED,
                'outcome' => NotificationFollowupOutcome::CLOSED_MANUALLY,
                'closed_at' => $now,
                'closed_by_user_id' => $user->getKey(),
                'close_reason_code' => $reason->value,
            ])->save();
            $this->events->followup($followup, NotificationEventType::FOLLOWUP_CLOSED, $side, $user, $reason->value, $note, [
                'stage' => $followup->stage->value,
                'outcome' => NotificationFollowupOutcome::CLOSED_MANUALLY->value,
            ]);

            return $followup;
        });
    }
}
