<?php

namespace App\Jobs;

use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The notification worker heartbeat (FB-NOTIFY-01B.2b): dispatched every
 * minute by the scheduler (routes/console.php) onto the dedicated
 * notifications queue; when a worker runs it, it records the server time.
 * A fresh heartbeat therefore proves both the scheduler and the worker.
 *
 * It carries no payload, reads and writes no family or personal data,
 * publishes nothing and touches no notification table. Running it twice is
 * harmless (last writer wins), so it is never retried: the next minute's
 * heartbeat replaces a lost one.
 */
class NotificationHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        // An invalid queue name keeps the default name; readiness reports it.
        $this->onQueue(NotificationConfig::queue() ?? NotificationConfig::DEFAULT_QUEUE);
    }

    public function handle(): void
    {
        NotificationHeartbeat::record();
    }
}
