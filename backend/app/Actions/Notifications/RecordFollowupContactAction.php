<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupContactOutcome;
use App\Exceptions\NotificationFollowupException;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\FollowupManagement;
use App\Support\Notifications\NotificationEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Records a contact attempt on an OPEN follow-up (FB-NOTIFY-01E.3-F3.1; ADR
 * FB-NOTIFY-01 ADR-06, §2c): one append-only CONTACT_RECORDED event with a
 * contact-outcome code (NotificationFollowupContactOutcome) and an optional
 * internal note (1..1000 characters, never shown to families, never logged).
 *
 * Central Staff, or the family's CURRENT primary coordinator only — see
 * FollowupManagement (locks, re-checked authorization, expiry). It never
 * closes the follow-up, never touches the recipient (no view, no
 * acknowledgement: CALLED_INFORMED means told, not acknowledged) and never
 * acts for the family. A refusal writes nothing.
 */
class RecordFollowupContactAction
{
    public function __construct(
        private readonly FollowupManagement $management,
        private readonly NotificationEventRecorder $events,
    ) {}

    public function handle(User $actor, string $followupUuid, NotificationFollowupContactOutcome|string $outcome, ?string $note = null): NotificationRecipientEvent
    {
        $outcome = $outcome instanceof NotificationFollowupContactOutcome ? $outcome : NotificationFollowupContactOutcome::tryFrom($outcome)
            ?? throw new NotificationFollowupException(NotificationFollowupException::REASON_INVALID);
        $note = FollowupManagement::note($note);
        FollowupManagement::assertMayManageAny($actor);

        return DB::transaction(function () use ($actor, $followupUuid, $outcome, $note) {
            ['followup' => $followup, 'actor' => $user, 'side' => $side] = $this->management->lockAndAuthorize($actor, $followupUuid);

            return $this->events->followup($followup, NotificationEventType::CONTACT_RECORDED, $side, $user, $outcome->value, $note, [
                'stage' => $followup->stage->value,
            ]);
        });
    }
}
