<?php

namespace App\Support\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCategory;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationRetryDecision as Decision;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use Carbon\CarbonImmutable;

/**
 * The ONE retry policy of notification publication (FB-NOTIFY-01B.2b.5b; ADR
 * FB-NOTIFY-01 R4 as decided): decisions only — it reads, never writes,
 * dispatches or locks. Callers pass the message re-read under its row lock
 * (RetryPublicationAction now; the reconciliation worker in 2b.5d).
 *
 * Terms. publish_attempts keeps its meaning: the number of worker claims
 * (QUEUED → PUBLISHING) — every claim is an attempt, a recovered stale one
 * included. A MANUAL retry is a REQUEUED event attributed to STAFF or
 * COORDINATOR; an AUTOMATIC one is a REQUEUED event attributed to SYSTEM.
 * notification_recipient_events is append-only (trigger + model), and the
 * schema already separates the two (chk_nre_actor: SYSTEM has no user, the
 * other sides one; FAMILY never writes message events) — so the manual count
 * cannot be edited away. It is read in the caller's transaction, under the
 * message lock that every REQUEUED write also holds.
 *
 * Rules (first failing check wins):
 *
 *   both     PUBLISH_FAILED · valid configuration · a known failure code ·
 *            not PERMANENT
 *   automatic  not MANUAL_ONLY · publish_attempts < automatic_publish_attempts
 *            (3) · expires_at at least the R2 lead ahead · the backoff after
 *            the last failure elapsed (1 min after the 1st attempt, 5 min after
 *            the 2nd)
 *   manual   manual retries used < manual_publish_retries (1) ·
 *            publish_attempts < the claim limit · a TRANSIENT failure only
 *            once the automatic attempts are used up (MANUAL_ONLY at once) ·
 *            expires_at at least the R2 lead ahead
 *
 * The claim limit is automatic + manual, never above MAX_TOTAL_CLAIMS (4).
 * Automatic retries stop at the automatic budget even after a manual retry,
 * and the manual allowance never grows, so no message is ever claimed more
 * than 4 times and nothing is retried without limit.
 */
final class NotificationRetryPolicy
{
    public const MAX_TOTAL_CLAIMS = 4;

    /** Backoff in seconds after the Nth failed attempt (the last entry for any later one). */
    public const BACKOFF_SECONDS = [1 => 60, 2 => 300];

    /** Manual retries already used: REQUEUED events by STAFF or COORDINATOR. */
    public function manualRetriesUsed(NotificationMessage $message): int
    {
        return NotificationRecipientEvent::query()
            ->where('message_id', $message->getKey())
            ->where('event_type', NotificationEventType::REQUEUED->value)
            ->whereIn('actor_side', [WorkflowActorSide::STAFF->value, WorkflowActorSide::COORDINATOR->value])
            ->count();
    }

    /** The most worker claims a message may ever have, or NULL when the configuration is invalid. */
    public function claimLimit(): ?int
    {
        $automatic = NotificationConfig::automaticPublishAttempts();
        $manual = NotificationConfig::manualPublishRetries();

        return $automatic === null || $manual === null ? null : min($automatic + $manual, self::MAX_TOTAL_CLAIMS);
    }

    /** May the reconciliation worker requeue this failed message now? */
    public function automatic(NotificationMessage $message): Decision
    {
        $failure = $this->retryableFailure($message);
        if ($failure instanceof Decision) {
            return $failure;
        }
        if ($failure->category() === NotificationFailureCategory::MANUAL_ONLY) {
            return Decision::MANUAL_ONLY_FAILURE;
        }
        if ($message->publish_attempts >= NotificationConfig::automaticPublishAttempts()) {
            return Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED;
        }
        if ($message->publish_attempts >= $this->claimLimit()) {
            return Decision::CLAIM_LIMIT_REACHED;
        }
        if (! $this->expiryAllows($message)) {
            return Decision::EXPIRY_TOO_SOON;
        }
        $retryAt = $this->automaticRetryAt($message);

        return $retryAt === null || now()->getTimestamp() < $retryAt->getTimestamp() ? Decision::BACKOFF_PENDING : Decision::ALLOWED;
    }

    /** May the sender retry this failed message now? */
    public function manual(NotificationMessage $message): Decision
    {
        $failure = $this->retryableFailure($message);
        if ($failure instanceof Decision) {
            return $failure;
        }
        if ($this->manualRetriesUsed($message) >= NotificationConfig::manualPublishRetries()) {
            return Decision::MANUAL_RETRIES_EXHAUSTED;
        }
        if ($message->publish_attempts >= $this->claimLimit()) {
            return Decision::CLAIM_LIMIT_REACHED;
        }
        if ($failure->category() === NotificationFailureCategory::TRANSIENT
            && $message->publish_attempts < NotificationConfig::automaticPublishAttempts()) {
            return Decision::AUTOMATIC_RETRY_PENDING;
        }

        return $this->expiryAllows($message) ? Decision::ALLOWED : Decision::EXPIRY_TOO_SOON;
    }

    /**
     * When the backoff after the last failure ends: publish_failed_at plus
     * 1 minute after the 1st attempt, 5 minutes after the 2nd or later. NULL
     * without a failure time (never true of PUBLISH_FAILED: chk_nm_failed).
     */
    public function automaticRetryAt(NotificationMessage $message): ?CarbonImmutable
    {
        if ($message->publish_failed_at === null) {
            return null;
        }
        $attempt = max(1, min((int) $message->publish_attempts, array_key_last(self::BACKOFF_SECONDS)));

        return CarbonImmutable::instance($message->publish_failed_at)->addSeconds(self::BACKOFF_SECONDS[$attempt]);
    }

    /** The checks both retries share, or the decision that refuses. */
    private function retryableFailure(NotificationMessage $message): NotificationFailureCode|Decision
    {
        if ($message->status !== NotificationStatus::PUBLISH_FAILED) {
            return Decision::NOT_FAILED;
        }
        if ($this->claimLimit() === null) {
            return Decision::CONFIG_INVALID;
        }
        $failure = NotificationFailureCode::tryFrom((string) $message->publish_failure_code);
        if ($failure === null) {
            return Decision::UNKNOWN_FAILURE;
        }

        return $failure->isPermanent() ? Decision::PERMANENT_FAILURE : $failure;
    }

    /** No expiry, or one still at least the R2 lead ahead. */
    private function expiryAllows(NotificationMessage $message): bool
    {
        return $message->expires_at === null
            || $message->expires_at->getTimestamp() >= now()->getTimestamp() + NotificationLifecycle::MIN_EXPIRY_LEAD_SECONDS;
    }
}
