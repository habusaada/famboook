<?php

namespace App\Support\Notifications;

use App\Enums\NotificationHeartbeatState;
use Illuminate\Support\Facades\Cache;

/**
 * The notification worker heartbeat (FB-NOTIFY-01B.2b): a single cache entry
 * holding the server time (Unix seconds) at which NotificationHeartbeatJob
 * last RAN on a worker. It carries no family, person or user data.
 *
 * ONE time source: both the write and the freshness calculation use the
 * application clock (now()), never a client value or the cache's own clock.
 * Writes are last-writer-wins, so duplicate or overlapping jobs are harmless.
 * A heartbeat in the future (a clock jump) is treated as STALE: fail closed.
 *
 * The entry must live in a cache store shared by the worker and the web /
 * console processes; a per-process store (array) simply never shows a
 * worker's heartbeat, so readiness stays closed.
 */
final class NotificationHeartbeat
{
    public const CACHE_KEY = 'famboook:notifications:worker-heartbeat';

    /** Long enough to tell STALE from ABSENT; freshness never relies on it. */
    public const TTL_SECONDS = 86400;

    public static function record(): void
    {
        Cache::put(self::CACHE_KEY, now()->getTimestamp(), self::TTL_SECONDS);
    }

    /** Seconds since the last heartbeat, or NULL when there is none. */
    public static function ageSeconds(): ?int
    {
        $beat = Cache::get(self::CACHE_KEY);

        return is_int($beat) ? now()->getTimestamp() - $beat : null;
    }

    public static function state(): NotificationHeartbeatState
    {
        $age = self::ageSeconds();
        if ($age === null) {
            return NotificationHeartbeatState::ABSENT;
        }
        $maxAge = NotificationConfig::heartbeatMaxAgeSeconds();

        return $maxAge !== null && $age >= 0 && $age <= $maxAge
            ? NotificationHeartbeatState::FRESH
            : NotificationHeartbeatState::STALE;
    }
}
