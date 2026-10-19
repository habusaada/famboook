<?php

namespace App\Actions\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationLifecycle;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Support\Facades\DB;

/**
 * The sender retries a failed publication: PUBLISH_FAILED → QUEUED
 * (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01 ADR-08 rule 6, R4).
 *
 * The same gates as a first request — WHO (send permission, a
 * coordinator's CURRENT context), own message, readiness — then, under the
 * message row lock:
 * - PUBLISH_FAILED only (QUEUED or further on is a replay);
 * - the last failure is retryable: a permanent NotificationFailureCode
 *   (SENDER_NOT_AUTHORIZED, AUDIENCE_INVALID, EXPIRED_BEFORE_PUBLICATION)
 *   or an unknown code refuses (NOTIFICATION_RETRY_NOT_ALLOWED) — fail
 *   closed; the sender may cancel;
 * - fewer publication attempts than notifications.max_publish_attempts (3)
 *   in total — publish_attempts counts the worker's claims; at the limit
 *   only cancellation remains (NOTIFICATION_RETRY_LIMIT_REACHED). OPEN
 *   DECISION: design alignment R4 reads "three automatic attempts, then
 *   manual retry or cancel"; this total cap of 3 differs and must be decided
 *   before automatic reconciliation (FB-NOTIFY-01B.2b.5) is built. Manual
 *   retries are never unlimited;
 * - the expiry lead (R2), the audience against the CURRENT eligibility and
 *   scope, a fresh coordinator scope snapshot;
 * - QUEUED with a new queued_at and the REQUEUED event, atomically. The
 *   latest failure time and code stay (chk_nm_failure: history kept).
 *
 * The hand-off goes through the same after-commit dispatch boundary as a
 * first request; nothing is published here.
 */
class RetryPublicationAction
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
            if ($locked->status !== NotificationStatus::PUBLISH_FAILED) {
                throw new NotificationPublicationException(NotificationPublicationException::INVALID_TRANSITION);
            }
            NotificationLifecycle::assertAllowed($locked->status, NotificationStatus::QUEUED);

            $failure = NotificationFailureCode::tryFrom((string) $locked->publish_failure_code);
            if ($failure === null || $failure->isPermanent()) {
                throw new NotificationPublicationException(NotificationPublicationException::RETRY_NOT_ALLOWED);
            }
            $limit = NotificationConfig::maxPublishAttempts();
            if ($limit === null || $locked->publish_attempts >= $limit) {
                throw new NotificationPublicationException(NotificationPublicationException::RETRY_LIMIT_REACHED);
            }
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
            $this->events->message($locked, NotificationEventType::REQUEUED, $sender->actorSide(), $sender->user, $failure->value,
                ['attempts' => $locked->publish_attempts]);

            DB::afterCommit(fn () => $this->dispatcher->dispatch($locked));

            return new NotificationOutcome($locked);
        });
    }
}
