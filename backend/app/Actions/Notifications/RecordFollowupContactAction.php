<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupContactOutcome;
use App\Exceptions\NotificationFollowupException;
use App\Models\NotificationFollowup;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\FollowupManagement;
use App\Support\Notifications\NotificationEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 *
 * Idempotency (F3.2, §2f): with a client_reference (a uuid; required by the
 * API), one contact per (follow-up, acting user, client_reference). The same
 * reference with the same outcome and note returns the ORIGINAL event
 * (replayed) — decided under the recipient and follow-up locks, before
 * authorization; a different outcome or note: CLIENT_REFERENCE_CONFLICT. The
 * partial unique index is the final guard. The key never crosses actors or
 * follow-ups.
 */
class RecordFollowupContactAction
{
    public function __construct(
        private readonly FollowupManagement $management,
        private readonly NotificationEventRecorder $events,
    ) {}

    public function handle(User $actor, string $followupUuid, NotificationFollowupContactOutcome|string $outcome, ?string $note = null, ?string $clientReference = null): NotificationRecipientEvent
    {
        return $this->record($actor, $followupUuid, $outcome, $note, $clientReference)[0];
    }

    /** @return array{0: NotificationRecipientEvent, 1: bool} the event and whether it is a replay */
    public function record(User $actor, string $followupUuid, NotificationFollowupContactOutcome|string $outcome, ?string $note = null, ?string $clientReference = null): array
    {
        $outcome = $outcome instanceof NotificationFollowupContactOutcome ? $outcome : NotificationFollowupContactOutcome::tryFrom($outcome)
            ?? throw new NotificationFollowupException(NotificationFollowupException::REASON_INVALID);
        $note = FollowupManagement::note($note);
        if ($clientReference !== null && ! Str::isUuid($clientReference)) {
            throw new NotificationFollowupException(NotificationFollowupException::CLIENT_REFERENCE_INVALID);
        }
        $clientReference = $clientReference === null ? null : strtolower($clientReference);
        FollowupManagement::assertMayManageAny($actor);

        return DB::transaction(function () use ($actor, $followupUuid, $outcome, $note, $clientReference) {
            $replay = $clientReference === null ? null
                : fn (NotificationFollowup $followup) => $this->replay($followup, $actor, $clientReference, $outcome, $note);
            $locked = $this->management->lockAndAuthorize($actor, $followupUuid, $replay);
            if (isset($locked['replay'])) {
                return [$locked['replay'], true];
            }
            ['followup' => $followup, 'actor' => $user, 'side' => $side] = $locked;

            try {
                // A savepoint: the partial unique index is the final guard.
                $event = DB::transaction(fn () => $this->events->followup($followup, NotificationEventType::CONTACT_RECORDED, $side, $user,
                    $outcome->value, $note, ['stage' => $followup->stage->value], $clientReference));
            } catch (UniqueConstraintViolationException $e) {
                return [$this->replay($followup, $actor, (string) $clientReference, $outcome, $note) ?? throw $e, true];
            }

            return [$event, false];
        });
    }

    /** The actor's earlier contact with this reference on this follow-up; a different payload conflicts. */
    private function replay(NotificationFollowup $followup, User $actor, string $clientReference, NotificationFollowupContactOutcome $outcome, ?string $note): ?NotificationRecipientEvent
    {
        $existing = NotificationRecipientEvent::query()
            ->where('followup_id', $followup->getKey())
            ->where('actor_user_id', $actor->getKey())
            ->where('client_reference', $clientReference)
            ->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->reason_code !== $outcome->value || $existing->internal_note !== $note) {
            throw new NotificationFollowupException(NotificationFollowupException::CLIENT_REFERENCE_CONFLICT);
        }

        return $existing;
    }
}
