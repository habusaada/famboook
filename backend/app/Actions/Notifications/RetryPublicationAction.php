<?php

namespace App\Actions\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationRetryDecision;
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
use App\Support\Notifications\NotificationRetryPolicy;
use Illuminate\Support\Facades\DB;

/**
 * The sender's MANUAL retry of a failed publication: PUBLISH_FAILED → QUEUED
 * (FB-NOTIFY-01B.2b.4, retry policy FB-NOTIFY-01B.2b.5b; ADR FB-NOTIFY-01
 * ADR-08 rule 6, R4 as decided).
 *
 * The same gates as a first request — WHO (send permission, a
 * coordinator's CURRENT context), own message, readiness — then, under the
 * message row lock:
 * - PUBLISH_FAILED only (QUEUED or further on is a replay; anything else a
 *   conflict);
 * - NotificationRetryPolicy::manual(): a known, non-permanent failure; the
 *   manual allowance (1) unused — counted from this message's REQUEUED
 *   events by STAFF or COORDINATOR; fewer worker claims than the limit (4);
 *   a TRANSIENT failure only once the automatic attempts (3) are used up,
 *   a MANUAL_ONLY one at once; the expiry lead (R2);
 * - the audience against the CURRENT eligibility and scope, a fresh
 *   coordinator scope snapshot;
 * - QUEUED with a new queued_at and the REQUEUED event attributed to the
 *   sender (STAFF or COORDINATOR — which is what makes it count as the
 *   manual retry), atomically. The latest failure time and code stay
 *   (chk_nm_failure: history kept). publish_attempts is not touched: the
 *   worker counts its claims.
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
        private readonly NotificationRetryPolicy $policy,
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
            NotificationLifecycle::assertAllowed($locked->status, NotificationStatus::QUEUED);
            if ($locked->status !== NotificationStatus::PUBLISH_FAILED) {
                throw new NotificationPublicationException(NotificationPublicationException::INVALID_TRANSITION);
            }

            $decision = $this->policy->manual($locked);
            if (! $decision->allowed()) {
                throw new NotificationPublicationException(self::refusal($decision));
            }

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
            $this->events->message($locked, NotificationEventType::REQUEUED, $sender->actorSide(), $sender->user,
                (string) $locked->publish_failure_code, ['attempts' => $locked->publish_attempts, 'retry' => 'MANUAL']);

            DB::afterCommit(fn () => $this->dispatcher->dispatch($locked));

            return new NotificationOutcome($locked);
        });
    }

    /** The sender-facing refusal for a policy decision (codes only). */
    private static function refusal(NotificationRetryDecision $decision): string
    {
        return match ($decision) {
            NotificationRetryDecision::UNKNOWN_FAILURE, NotificationRetryDecision::PERMANENT_FAILURE => NotificationPublicationException::RETRY_NOT_ALLOWED,
            NotificationRetryDecision::MANUAL_RETRIES_EXHAUSTED, NotificationRetryDecision::CLAIM_LIMIT_REACHED => NotificationPublicationException::RETRY_LIMIT_REACHED,
            NotificationRetryDecision::AUTOMATIC_RETRY_PENDING => NotificationPublicationException::AUTOMATIC_RETRY_PENDING,
            NotificationRetryDecision::EXPIRY_TOO_SOON => NotificationPublicationException::EXPIRY_TOO_SOON,
            NotificationRetryDecision::CONFIG_INVALID => NotificationPublicationException::PUBLICATION_UNAVAILABLE,
            default => NotificationPublicationException::INVALID_TRANSITION,
        };
    }
}
