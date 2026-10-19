<?php

namespace Tests\Support;

use App\Jobs\NotificationHeartbeatJob;
use Illuminate\Support\Facades\DB;

/**
 * FB-NOTIFY-01B.2b: the notification configuration under test. Starts from
 * the release defaults (everything OFF) whatever the developer's .env says;
 * `ready()` adds an asynchronous queue and a fresh worker heartbeat. The
 * queue is never actually worked: the async connection is only configured.
 */
trait NotificationReadinessFixtures
{
    protected function notificationDefaults(): void
    {
        config([
            'notifications.mode' => 'OFF',
            'notifications.pilot_sender_user_ids' => '',
            'notifications.system_discovery_enabled' => false,
            'notifications.followups_enabled' => false,
            'notifications.system_cutoff' => null,
            'notifications.queue' => 'notifications',
            'notifications.heartbeat_max_age_seconds' => 300,
            'notifications.automatic_publish_attempts' => 3,
            'notifications.manual_publish_retries' => 1,
            'notifications.legacy_max_publish_attempts' => null,
            'notifications.stale_publishing_seconds' => 600,
        ]);
    }

    /** An asynchronous queue connection (configured, never worked) and a fresh heartbeat. */
    protected function infrastructureReady(): void
    {
        config(['queue.default' => 'database']);
        $this->freshHeartbeat();
    }

    protected function freshHeartbeat(): void
    {
        (new NotificationHeartbeatJob)->handle();
    }

    /**
     * Runs $work and returns every INSERT / UPDATE / DELETE statement it sent.
     *
     * @return list<string>
     */
    protected function writesDuring(callable $work): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|truncate|drop|create|alter)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $work();

        return $writes;
    }
}
