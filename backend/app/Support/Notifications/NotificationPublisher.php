<?php

namespace App\Support\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationKind;
use App\Enums\NotificationPublicationResult as Result;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The asynchronous publication of ONE message (FB-NOTIFY-01B.2b.5c; ADR
 * FB-NOTIFY-01 ADR-04, ADR-08, ADR-09). Run by PublishNotificationJob on a
 * worker — never inside an HTTP request or another transaction.
 *
 * Lock order, everywhere: the notification_messages row (FOR UPDATE), then
 * the coordinator's coordinator_scope_assignments rows (FOR SHARE). Families
 * are never locked. On PostgreSQL every transaction sets lock_timeout 5s and
 * statement_timeout 45s locally (below the job's 60 s timeout).
 *
 * T1 claim — short: lock; still QUEUED (else NOT_CLAIMED: duplicate, stale or
 *   cancelled delivery); the gates (mode, PILOT sender, SYSTEM gate, valid
 *   configuration and queue timing) and the claim limit (4) — when one
 *   blocks, nothing is written and no attempt is consumed (PAUSED); then
 *   PUBLISHING with a fresh fencing token, publish_attempts + 1,
 *   publishing_started_at and the PUBLISHING_STARTED event.
 * T2 publication — one transaction: lock; still PUBLISHING with THIS token
 *   (else SUPERSEDED, nothing written); the gates again; expiry against one
 *   timestamp; for an announcement the sender re-read (active, side, the
 *   CURRENT send permission — Spatie's map refreshed first — and a
 *   coordinator's current context, the assignment rows locked FOR SHARE so a
 *   revocation either commits before or waits until after); the audience
 *   re-resolved (NotificationAudienceResolver::forMessage); every recipient
 *   by one INSERT … SELECT (NotificationRecipientSnapshot); at least one;
 *   PUBLISHED with published_at = that timestamp and recipient_count; the
 *   PUBLISHED event. Nothing is visible before it commits.
 * T3 failure — after T2 rolled back: lock; still PUBLISHING with THIS token
 *   (else SUPERSEDED); PUBLISH_FAILED with the time and a stable
 *   NotificationFailureCode; the PUBLISH_FAILED event. If T3 itself fails the
 *   message stays PUBLISHING for stale recovery (2b.5d).
 *
 * A gate closing between T1 and T2 (the mode switched OFF, the sender left
 * PILOT) never publishes: T2 refuses and T3 records the transient
 * PUBLICATION_ERROR with the cause code in the event. The claim's attempt is
 * already counted — the lifecycle has no PUBLISHING → QUEUED move.
 *
 * Logs carry the message id, codes and exception CLASS names only.
 */
final class NotificationPublisher
{
    public function __construct(
        private readonly NotificationAudienceResolver $audiences,
        private readonly NotificationEventRecorder $events,
        private readonly NotificationRetryPolicy $policy,
    ) {}

    /** T1, then T2, then T3 on failure. */
    public function publish(int $messageId): Result
    {
        // A long-running worker must not trust the permission → role map it
        // loaded at boot: reload it from the shared cache store.
        app(PermissionRegistrar::class)->clearPermissionsCollection();

        $claim = $this->claim($messageId);
        if ($claim instanceof Result) {
            return $claim;
        }

        try {
            return $this->attempt($messageId, $claim);
        } catch (NotificationPublicationFailure $failure) {
            return $this->recordFailure($messageId, $claim, $failure->failure, $failure->cause);
        } catch (Throwable $e) {
            Log::warning('Notification publication failed.', ['message_id' => $messageId, 'code' => NotificationFailureCode::PUBLICATION_ERROR->value, 'exception' => $e::class]);

            return $this->recordFailure($messageId, $claim, NotificationFailureCode::PUBLICATION_ERROR);
        }
    }

    /** T1: the fencing token of a new claim, or why nothing was claimed. */
    public function claim(int $messageId): string|Result
    {
        return DB::transaction(function () use ($messageId) {
            NotificationLifecycle::limitTransaction();
            $message = NotificationMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($message === null || ! NotificationLifecycle::mayStartPublishing($message)) {
                return Result::NOT_CLAIMED;
            }
            $blocked = NotificationPublicationGate::blocks($message) ?? (NotificationConfig::queueTimingValid() ? null : 'QUEUE_TIMING_INVALID');
            $limit = $this->policy->claimLimit();
            if ($blocked === null && ($limit === null || $message->publish_attempts >= $limit)) {
                $blocked = 'CLAIM_LIMIT_REACHED';
            }
            if ($blocked !== null) {
                Log::info('Notification publication paused.', ['message_id' => $messageId, 'code' => $blocked]);

                return Result::PAUSED;
            }

            NotificationLifecycle::assertAllowed($message->status, NotificationStatus::PUBLISHING);
            $token = (string) Str::uuid();
            $message->forceFill([
                'status' => NotificationStatus::PUBLISHING,
                'publishing_started_at' => now(),
                'publishing_token' => $token,
                'publish_attempts' => $message->publish_attempts + 1,
            ])->save();
            $this->events->message($message, NotificationEventType::PUBLISHING_STARTED, WorkflowActorSide::SYSTEM,
                metadata: ['attempt' => $message->publish_attempts]);

            return $token;
        });
    }

    /** T2: publish atomically, or throw NotificationPublicationFailure (rolled back). */
    public function attempt(int $messageId, string $token): Result
    {
        return DB::transaction(function () use ($messageId, $token) {
            NotificationLifecycle::limitTransaction();
            $message = $this->ownedClaim($messageId, $token);
            if ($message === null) {
                return Result::SUPERSEDED;
            }
            if (($blocked = NotificationPublicationGate::blocks($message)) !== null) {
                throw new NotificationPublicationFailure(NotificationFailureCode::PUBLICATION_ERROR, $blocked);
            }
            // ONE timestamp: the expiry check, published_at and the snapshot.
            $now = now();
            if ($message->expires_at !== null && $message->expires_at->getTimestamp() <= $now->getTimestamp()) {
                throw new NotificationPublicationFailure(NotificationFailureCode::EXPIRED_BEFORE_PUBLICATION);
            }

            $count = DB::table('notification_recipients')->insertUsing(
                NotificationRecipientSnapshot::COLUMNS,
                NotificationRecipientSnapshot::query($this->families($message), (int) $message->getKey(), $now),
            );
            if ($count < 1) {
                throw new NotificationPublicationFailure(NotificationFailureCode::AUDIENCE_EMPTY);
            }

            NotificationLifecycle::assertAllowed($message->status, NotificationStatus::PUBLISHED);
            $message->forceFill([
                'status' => NotificationStatus::PUBLISHED,
                'published_at' => $now,
                'recipient_count' => $count,
            ])->save();
            $this->events->message($message, NotificationEventType::PUBLISHED, WorkflowActorSide::SYSTEM,
                metadata: ['recipient_count' => $count]);

            return Result::PUBLISHED;
        });
    }

    /** T3: record the failure of THIS claim. */
    public function recordFailure(int $messageId, string $token, NotificationFailureCode $failure, ?string $cause = null): Result
    {
        try {
            return DB::transaction(function () use ($messageId, $token, $failure, $cause) {
                NotificationLifecycle::limitTransaction();
                $message = $this->ownedClaim($messageId, $token);
                if ($message === null) {
                    return Result::SUPERSEDED;
                }
                NotificationLifecycle::assertAllowed($message->status, NotificationStatus::PUBLISH_FAILED);
                $message->forceFill([
                    'status' => NotificationStatus::PUBLISH_FAILED,
                    'publish_failed_at' => now(),
                    'publish_failure_code' => $failure->value,
                ])->save();
                $this->events->message($message, NotificationEventType::PUBLISH_FAILED, WorkflowActorSide::SYSTEM,
                    reasonCode: $failure->value,
                    metadata: array_filter(['attempt' => $message->publish_attempts, 'cause' => $cause], fn ($value) => $value !== null));

                return Result::FAILED;
            });
        } catch (Throwable $e) {
            // The message stays PUBLISHING; stale recovery (2b.5d) takes it over.
            Log::error('Notification failure could not be recorded.', ['message_id' => $messageId, 'code' => $failure->value, 'exception' => $e::class]);

            throw $e;
        }
    }

    /** The message locked, if it is still PUBLISHING under THIS fencing token. */
    private function ownedClaim(int $messageId, string $token): ?NotificationMessage
    {
        $message = NotificationMessage::query()->whereKey($messageId)->lockForUpdate()->first();

        return $message !== null && $message->status === NotificationStatus::PUBLISHING && $message->publishing_token === $token
            ? $message
            : null;
    }

    /**
     * The families to publish to, re-authorized NOW. An announcement's sender
     * is re-read and must still be an active account of the message's side
     * with the send permission (a coordinator: a current context, its
     * assignment rows locked FOR SHARE first); the audience is re-resolved.
     *
     * @return Builder<Family>
     */
    private function families(NotificationMessage $message): Builder
    {
        if ($message->kind === NotificationKind::ANNOUNCEMENT) {
            if ($message->sender_side === NotificationSenderSide::COORDINATOR) {
                // Lock order: the message (held), then the assignments.
                CoordinatorScopeAssignment::query()->where('user_id', $message->sender_user_id)->orderBy('id')->sharedLock()->get(['id']);
            }
            $user = User::query()->find($message->sender_user_id);
            try {
                $sender = $user === null ? null : NotificationActors::sender($user, send: true);
            } catch (NotificationPublicationException) {
                $sender = null;
            }
            if ($sender === null || $sender->side !== $message->sender_side) {
                throw new NotificationPublicationFailure(NotificationFailureCode::SENDER_NOT_AUTHORIZED);
            }
        }

        try {
            return $this->audiences->forMessage($message);
        } catch (NotificationAudienceException $e) {
            throw new NotificationPublicationFailure($e->reason === NotificationAudienceException::NOT_AUTHORIZED
                ? NotificationFailureCode::SENDER_NOT_AUTHORIZED
                : NotificationFailureCode::AUDIENCE_INVALID);
        }
    }
}
