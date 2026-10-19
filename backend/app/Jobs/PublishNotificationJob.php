<?php

namespace App\Jobs;

use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\Permission\PermissionRegistrar;

/**
 * Publishes ONE notification message on a worker (FB-NOTIFY-01B.2b.5c),
 * through NotificationPublisher's T1 / T2 / T3. Handed off only after the
 * QUEUED state committed (QueueNotificationPublicationDispatcher).
 *
 * - Only the message id travels: the job re-reads and re-authorizes
 *   everything under the message row lock.
 * - One try, one exception, a hard 60 s limit (below the queue's
 *   retry_after and the stale threshold — NotificationConfig::
 *   queueTimingValid). Business failures are stored as PUBLISH_FAILED; a
 *   retry is a new hand-off decided by NotificationRetryPolicy, never a
 *   Laravel retry of this job.
 * - Not ShouldBeUnique: duplicates are harmless (only a QUEUED message can be
 *   claimed, once) and a uniqueness lock could block a re-dispatch after a
 *   crash.
 */
class PublishNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = NotificationConfig::PUBLICATION_JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue(NotificationConfig::queue() ?? NotificationConfig::DEFAULT_QUEUE);
    }

    public function handle(NotificationPublisher $publisher): void
    {
        // Never trust a long-running worker's permission → role map.
        app(PermissionRegistrar::class)->clearPermissionsCollection();

        $publisher->publish($this->messageId);
    }
}
