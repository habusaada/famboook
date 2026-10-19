<?php

namespace App\Support\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCategory;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationMode;
use App\Enums\NotificationRetryDecision;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\NotificationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publication recovery without manual intervention (FB-NOTIFY-01B.2b.5d; ADR
 * FB-NOTIFY-01 ADR-09 reconciliation, R4 as decided, R5). Run every minute by
 * famboook:notifications-reconcile. It NEVER publishes: every publication
 * still goes through PublishNotificationJob (T1 / T2 / T3).
 *
 * One run, at most $limit candidates in all, each in its OWN short
 * transaction on its row locked FOR UPDATE SKIP LOCKED (a row another session
 * holds — a live worker, another reconciler — is skipped, never waited for).
 * Only notification_messages rows are locked; the lock order of the worker
 * is unaffected. Hand-offs happen after commit (DB::afterCommit), never with
 * a transaction open; a failed push leaves the message QUEUED, recoverable.
 *
 * 1. Stale PUBLISHING (R5): publishing_started_at older than the stale
 *    threshold (600 s), re-checked under the lock → PUBLISH_FAILED with
 *    STALE_PUBLISHING, the attempt count untouched, the fencing token cleared
 *    (a late worker's T2 / T3 then finds neither the status nor its token)
 *    and a SYSTEM PUBLISH_FAILED event. Runs even when the mode is OFF.
 *    A live T2 holds the row lock, so it is never "recovered" (SKIP LOCKED).
 * 2. Automatic retry: NotificationRetryPolicy::automatic() — transient codes
 *    only, fewer than 3 attempts, 4 claims at most, the 60 s / 300 s backoff,
 *    the expiry lead → QUEUED with a new queued_at, a SYSTEM REQUEUED event
 *    (never STAFF / COORDINATOR: the manual allowance is untouched), then the
 *    hand-off.
 * 3. Orphaned QUEUED: no successful hand-off can be assumed after 180 s → the
 *    hand-off again, at most once per 300 s per message, with no state change
 *    and no event. The durable throttle is the row's updated_at: nothing else
 *    writes a QUEUED row, so updated_at is the queueing time until the first
 *    redispatch (which can come no earlier than queued_at + 180 s), and the
 *    last redispatch after it.
 *
 * Steps 2 and 3 can lead to publication, so they run only when dispatching
 * is safe (valid configuration, mode not OFF, an asynchronous queue with
 * compatible timing and a fresh worker heartbeat) and per message only
 * through NotificationPublicationGate (PILOT sender, SYSTEM gate and cutoff).
 * Otherwise they are counted as paused and nothing changes.
 *
 * Idempotent: every decision is re-taken under the row lock, so a second
 * run, or a concurrent reconciler, finds nothing left to do.
 */
final class NotificationPublicationReconciler
{
    public const DEFAULT_LIMIT = 100;

    /** A QUEUED message older than this may have lost its hand-off. */
    public const ORPHAN_AFTER_SECONDS = 180;

    /** At most one redispatch per message within this window. */
    public const REDISPATCH_THROTTLE_SECONDS = 300;

    public function __construct(
        private readonly NotificationRetryPolicy $policy,
        private readonly NotificationEventRecorder $events,
        private readonly NotificationPublicationDispatcher $dispatcher,
    ) {}

    public function run(int $limit = self::DEFAULT_LIMIT): NotificationReconciliationReport
    {
        $report = new NotificationReconciliationReport;
        $budget = max(0, $limit);

        $budget -= $this->recoverStale($report, $budget);

        if (($blocked = $this->dispatchBlocked()) !== null) {
            // Nothing that could lead to publication; show what is waiting.
            $waiting = $this->retryCandidates(ignoreGates: true)->count() + $this->orphanCandidates(ignoreGates: true)->count();
            $report->paused += min($waiting, $budget);
            Log::info('Notification reconciliation paused.', ['code' => $blocked]);
        } else {
            $budget -= $this->requeueFailures($report, $budget);
            $this->redispatchOrphans($report, $budget);
        }

        if (array_sum($report->toArray()) > 0) {
            Log::info('Notification reconciliation.', $report->toArray());
        }

        return $report;
    }

    // ------------------------------------------------------------ 1. stale

    private function recoverStale(NotificationReconciliationReport $report, int $budget): int
    {
        $stale = NotificationConfig::stalePublishingSeconds();
        if ($stale === null || $budget < 1) {
            return 0;
        }
        $ids = NotificationMessage::query()
            ->where('status', NotificationStatus::PUBLISHING->value)
            ->where('publishing_started_at', '<=', now()->subSeconds($stale))
            ->orderBy('publishing_started_at')->orderBy('id')
            ->limit($budget)->pluck('id');

        foreach ($ids as $id) {
            $this->step($report, (int) $id, function (NotificationMessage $message) use ($stale) {
                if ($message->status !== NotificationStatus::PUBLISHING
                    || $message->publishing_started_at === null
                    || $message->publishing_started_at->getTimestamp() > now()->getTimestamp() - $stale) {
                    return 'skipped';
                }
                NotificationLifecycle::assertAllowed($message->status, NotificationStatus::PUBLISH_FAILED);
                $message->forceFill([
                    'status' => NotificationStatus::PUBLISH_FAILED,
                    'publish_failed_at' => now(),
                    'publish_failure_code' => NotificationFailureCode::STALE_PUBLISHING->value,
                    // Fencing: the late worker's token no longer exists.
                    'publishing_token' => null,
                ])->save();
                $this->events->message($message, NotificationEventType::PUBLISH_FAILED, WorkflowActorSide::SYSTEM,
                    reasonCode: NotificationFailureCode::STALE_PUBLISHING->value,
                    metadata: ['attempt' => $message->publish_attempts, 'cause' => 'STALE_RECOVERED']);

                return 'staleRecovered';
            });
        }

        return $ids->count();
    }

    // ---------------------------------------------------- 2. automatic retry

    private function requeueFailures(NotificationReconciliationReport $report, int $budget): int
    {
        if ($budget < 1) {
            return 0;
        }
        $ids = $this->retryCandidates()->orderBy('publish_failed_at')->orderBy('id')->limit($budget)->pluck('id');

        foreach ($ids as $id) {
            $this->step($report, (int) $id, function (NotificationMessage $message) {
                if (NotificationPublicationGate::blocks($message) !== null) {
                    return 'paused';
                }
                if ($this->policy->automatic($message) !== NotificationRetryDecision::ALLOWED) {
                    return 'skipped';
                }
                NotificationLifecycle::assertAllowed($message->status, NotificationStatus::QUEUED);
                $message->forceFill(['status' => NotificationStatus::QUEUED, 'queued_at' => now()])->save();
                $this->events->message($message, NotificationEventType::REQUEUED, WorkflowActorSide::SYSTEM,
                    reasonCode: (string) $message->publish_failure_code,
                    metadata: ['attempts' => $message->publish_attempts, 'retry' => 'AUTOMATIC']);
                DB::afterCommit(fn () => $this->dispatcher->dispatch($message));

                return 'automaticRequeued';
            });
        }

        return $ids->count();
    }

    // ----------------------------------------------------- 3. orphaned QUEUED

    private function redispatchOrphans(NotificationReconciliationReport $report, int $budget): int
    {
        if ($budget < 1) {
            return 0;
        }
        $ids = $this->orphanCandidates()->orderBy('updated_at')->orderBy('id')->limit($budget)->pluck('id');

        foreach ($ids as $id) {
            $this->step($report, (int) $id, function (NotificationMessage $message) {
                if ($message->status !== NotificationStatus::QUEUED || ! self::orphanEligible($message)) {
                    return 'skipped';
                }
                if (NotificationPublicationGate::blocks($message) !== null) {
                    return 'paused';
                }
                // The durable throttle: only updated_at moves.
                $message->touch();
                DB::afterCommit(fn () => $this->dispatcher->dispatch($message));

                return 'orphanedRedispatched';
            });
        }

        return $ids->count();
    }

    /** May this QUEUED message be handed off again now (180 s, then once per 300 s)? */
    public static function orphanEligible(NotificationMessage $message): bool
    {
        if ($message->queued_at === null || $message->updated_at === null) {
            return false;
        }
        $now = now()->getTimestamp();
        $queued = $message->queued_at->getTimestamp();
        $touched = $message->updated_at->getTimestamp();
        $neverRedispatched = $touched < $queued + self::ORPHAN_AFTER_SECONDS;

        return $queued <= $now - self::ORPHAN_AFTER_SECONDS
            && ($neverRedispatched || $touched <= $now - self::REDISPATCH_THROTTLE_SECONDS);
    }

    // ---------------------------------------------------------- candidates

    /** @return Builder<NotificationMessage> */
    private function retryCandidates(bool $ignoreGates = false): Builder
    {
        $transient = array_values(array_map(fn (NotificationFailureCode $code) => $code->value, array_filter(
            NotificationFailureCode::cases(), fn (NotificationFailureCode $code) => $code->category() === NotificationFailureCategory::TRANSIENT)));
        $minimumBackoff = min(NotificationRetryPolicy::BACKOFF_SECONDS);

        $query = NotificationMessage::query()
            ->where('status', NotificationStatus::PUBLISH_FAILED->value)
            ->whereIn('publish_failure_code', $transient)
            ->where('publish_attempts', '<', NotificationConfig::automaticPublishAttempts() ?? 0)
            ->where('publish_failed_at', '<=', now()->subSeconds($minimumBackoff))
            ->where(fn ($q) => $q->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()->addSeconds(NotificationLifecycle::MIN_EXPIRY_LEAD_SECONDS)));

        return $ignoreGates ? $query : NotificationPublicationGate::narrow($query);
    }

    /** @return Builder<NotificationMessage> */
    private function orphanCandidates(bool $ignoreGates = false): Builder
    {
        $firstEligible = DB::getDriverName() === 'pgsql'
            ? "updated_at < queued_at + interval '".self::ORPHAN_AFTER_SECONDS." seconds'"
            : "updated_at < datetime(queued_at, '+".self::ORPHAN_AFTER_SECONDS." seconds')";

        $query = NotificationMessage::query()
            ->where('status', NotificationStatus::QUEUED->value)
            ->where('queued_at', '<=', now()->subSeconds(self::ORPHAN_AFTER_SECONDS))
            ->where(fn ($q) => $q->whereRaw($firstEligible)
                ->orWhere('updated_at', '<=', now()->subSeconds(self::REDISPATCH_THROTTLE_SECONDS)));

        return $ignoreGates ? $query : NotificationPublicationGate::narrow($query);
    }

    /**
     * Why nothing that could lead to publication may happen now, or NULL:
     * valid configuration, mode not OFF, and the dispatching infrastructure
     * (asynchronous queue, compatible timing, fresh heartbeat).
     */
    private function dispatchBlocked(): ?string
    {
        if (NotificationConfig::invalidKeys() !== []) {
            return 'CONFIG_INVALID';
        }
        if (NotificationConfig::mode() === NotificationMode::OFF) {
            return 'FEATURE_OFF';
        }

        return NotificationReadiness::infrastructure() === [] ? null : 'INFRASTRUCTURE_NOT_READY';
    }

    /**
     * One candidate in its own short transaction, its row locked FOR UPDATE
     * SKIP LOCKED. A row held elsewhere is skipped; a failure rolls the step
     * back and is counted (the next run retries it). The outcome — the name
     * of a report counter — is counted only once the step committed.
     *
     * @param  callable(NotificationMessage): string  $action
     */
    private function step(NotificationReconciliationReport $report, int $messageId, callable $action): void
    {
        $report->scanned++;
        try {
            $outcome = DB::transaction(function () use ($messageId, $action) {
                NotificationLifecycle::limitTransaction();
                $message = NotificationLifecycle::lockOrSkip($messageId);

                return $message === null ? 'skipped' : $action($message);
            });
            $report->{$outcome}++;
        } catch (Throwable $e) {
            $report->errors++;
            Log::warning('Notification reconciliation step failed.', ['message_id' => $messageId, 'code' => 'RECONCILIATION_ERROR', 'exception' => $e::class]);
        }
    }
}
