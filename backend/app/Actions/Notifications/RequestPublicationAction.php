<?php

namespace App\Actions\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationLifecycle;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Support\Facades\DB;

/**
 * The sender requests publication of their DRAFT: DRAFT → QUEUED
 * (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01 ADR-08 rule 1, R2).
 *
 * WHO first (the send permission of the account's side; a coordinator's
 * CURRENT context), then the sender's own message, then readiness
 * (NotificationReadiness: mode, PILOT allow-list, asynchronous queue, fresh
 * heartbeat — never a synchronous fallback). Under the message row lock:
 * DRAFT only, the expiry lead (R2), the audience re-validated against the
 * CURRENT eligibility and scope (send-all included), a fresh coordinator
 * scope snapshot, QUEUED with queued_at and its QUEUED event — one
 * transaction, so the state and its audit commit together or not at all.
 *
 * The hand-off to the worker happens only AFTER commit
 * (NotificationPublicationDispatcher via DB::afterCommit): a rollback
 * dispatches nothing, and nothing is ever published here. No recipient is
 * written: the worker writes the snapshot and PUBLISHED atomically, after
 * re-checking the sender (ADR-04, ADR-08 rule 2).
 *
 * A message already QUEUED or further on is a replay (nothing written, no
 * new dispatch). CANCELLED and PUBLISH_FAILED are conflicts: a failed
 * message is retried with RetryPublicationAction.
 */
class RequestPublicationAction
{
    public function __construct(
        private readonly NotificationAudienceResolver $audiences,
        private readonly NotificationEventRecorder $events,
        private readonly NotificationPublicationDispatcher $dispatcher,
    ) {}

    public function handle(User $user, NotificationMessage $message): NotificationOutcome
    {
        $sender = NotificationActors::sender($user, send: true);
        NotificationActors::ownMessage($message, $sender);
        NotificationActors::assertReady($sender);

        return DB::transaction(function () use ($sender, $message) {
            $locked = NotificationLifecycle::lock($message);
            NotificationActors::ownMessage($locked, $sender);
            if (in_array($locked->status, [NotificationStatus::QUEUED, NotificationStatus::PUBLISHING, NotificationStatus::PUBLISHED, NotificationStatus::ARCHIVED], true)) {
                return new NotificationOutcome($locked, replayed: true);
            }
            // DRAFT only: PUBLISH_FAILED → QUEUED is the retry's move, CANCELLED is final.
            if ($locked->status !== NotificationStatus::DRAFT) {
                throw new NotificationPublicationException(NotificationPublicationException::INVALID_TRANSITION);
            }
            NotificationLifecycle::assertAllowed($locked->status, NotificationStatus::QUEUED);
            NotificationLifecycle::assertExpiryLead($locked->expires_at);

            $audience = NotificationAudience::fromArray($locked->audience);
            if ($audience->type->senderSide() !== $sender->side) {
                throw new NotificationAudienceException(NotificationAudienceException::INVALID);
            }
            $this->audiences->validateRequest($sender->user, $audience);

            $locked->forceFill([
                'status' => NotificationStatus::QUEUED,
                'queued_at' => now(),
                'sender_scope' => $sender->scopeSnapshot(),
            ])->save();
            $this->events->message($locked, NotificationEventType::QUEUED, $sender->actorSide(), $sender->user,
                metadata: ['audience_type' => $audience->type->value]);

            DB::afterCommit(fn () => $this->dispatcher->dispatch($locked));

            return new NotificationOutcome($locked);
        });
    }
}
