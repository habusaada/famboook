<?php

namespace App\Support\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Jobs\PublishNotificationJob;
use App\Models\NotificationMessage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The production NotificationPublicationDispatcher (FB-NOTIFY-01B.2b.5c):
 * hands a committed QUEUED message to PublishNotificationJob on the
 * notifications queue. Called only from the lifecycle actions'
 * DB::afterCommit boundary.
 *
 * - NEVER synchronous: on a `sync` or `null` (or undefined) default queue
 *   connection nothing is dispatched — a sync dispatch would publish inside
 *   the caller's process.
 * - Never throws: a failed push (queue database down, …) is logged and the
 *   message stays QUEUED — committed, unchanged — for reconciliation
 *   (2b.5d), exactly like a lost hand-off.
 * - Logs the public message uuid, a code and the exception CLASS only.
 */
final class QueueNotificationPublicationDispatcher implements NotificationPublicationDispatcher
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function dispatch(NotificationMessage $message): void
    {
        if (! NotificationReadiness::queueIsAsynchronous()) {
            Log::warning('Notification hand-off refused: the queue connection is not asynchronous.', ['message' => $message->uuid, 'code' => 'QUEUE_NOT_ASYNC']);

            return;
        }

        try {
            $this->bus->dispatch(new PublishNotificationJob((int) $message->getKey()));
        } catch (Throwable $e) {
            Log::warning('Notification hand-off failed; the message stays QUEUED.', ['message' => $message->uuid, 'code' => 'DISPATCH_FAILED', 'exception' => $e::class]);
        }
    }
}
