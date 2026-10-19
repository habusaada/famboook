<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationLifecycle;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sender withdraws their announcement before publication
 * (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01 ADR-08, D3): DRAFT, QUEUED or
 * PUBLISH_FAILED → CANCELLED, with the cancelling user, time, an optional
 * reason CODE and the CANCELLED event, in one transaction. The failure
 * history of a PUBLISH_FAILED message is kept.
 *
 * Authorization (NotificationActors::canceller): ownership first — the
 * stored sender, anyone else's message is NOT_FOUND, another coordinator's
 * included — then an active account of the message's own side with that
 * side's create permission for a DRAFT, its send permission once
 * publication was requested. NO current Coordinator context is needed: a
 * coordinator whose assignments were revoked can still withdraw their own
 * message, and gains nothing else by it (requesting and retrying still need
 * the current context). No administrator override. No readiness check:
 * withdrawing is always possible, even with the feature OFF.
 *
 * PUBLISHING, PUBLISHED and ARCHIVED are conflicts (a published message is
 * never cancelled; the worker owns PUBLISHING). Cancelling one's own
 * already-cancelled message again is a replay.
 *
 * Race with the worker: both take the message row lock. If the cancel
 * commits first, the worker's claim (NotificationLifecycle::
 * mayStartPublishing: still QUEUED) fails and an already-dispatched job
 * publishes nothing; if the worker claimed first, the message is
 * PUBLISHING and the cancel is refused.
 */
class CancelPublicationAction
{
    public function __construct(private readonly NotificationEventRecorder $events) {}

    public function handle(User $user, NotificationMessage $message, ?string $reasonCode = null): NotificationOutcome
    {
        $sender = NotificationActors::canceller($user, $message);
        if ($reasonCode !== null && preg_match(NotificationEventRecorder::CODE_PATTERN, $reasonCode) !== 1) {
            throw ValidationException::withMessages(['reason_code' => 'رمز سبب الإلغاء غير صالح.']);
        }

        return DB::transaction(function () use ($sender, $message, $reasonCode) {
            $locked = NotificationLifecycle::lock($message);
            NotificationActors::ownMessage($locked, $sender);
            if ($locked->status === NotificationStatus::CANCELLED && (int) $locked->cancelled_by_user_id === (int) $sender->user->getKey()) {
                return new NotificationOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            NotificationLifecycle::assertAllowed($from, NotificationStatus::CANCELLED);
            if ($from !== NotificationStatus::DRAFT && ! NotificationActors::maySend($sender)) {
                throw new NotificationPublicationException(NotificationPublicationException::ACTOR_NOT_ALLOWED);
            }

            $locked->forceFill([
                'status' => NotificationStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $sender->user->getKey(),
                'cancellation_reason_code' => $reasonCode,
            ])->save();
            $this->events->message($locked, NotificationEventType::CANCELLED, $sender->actorSide(), $sender->user, $reasonCode,
                ['from_status' => $from->value]);

            return new NotificationOutcome($locked);
        });
    }
}
