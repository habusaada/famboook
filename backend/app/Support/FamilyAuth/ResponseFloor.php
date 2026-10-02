<?php

namespace App\Support\FamilyAuth;

use Illuminate\Support\Sleep;

/**
 * A minimum response time for the public activation steps that may send an
 * SMS (docs/11 §30a). An eligible request does a lookup, a transaction and a
 * synchronous delivery; a denied one does almost nothing — without a floor
 * the difference would tell the two apart.
 *
 * config family_auth.activation.min_response_ms (0 disables). The default is
 * a development value: the Production value must exceed the real SMS
 * provider's slow-case latency and is reviewed with that integration.
 */
final class ResponseFloor
{
    /** Milliseconds still owed after $elapsedMs of work. */
    public static function remainingMs(float $elapsedMs, ?int $floorMs = null): int
    {
        $floorMs ??= (int) config('family_auth.activation.min_response_ms');

        return max(0, $floorMs - (int) floor(max(0.0, $elapsedMs)));
    }

    /** Waits out what is left of the floor since $startedAt (microtime(true)). */
    public static function hold(float $startedAt): void
    {
        $remaining = self::remainingMs((microtime(true) - $startedAt) * 1000);
        if ($remaining > 0) {
            Sleep::for($remaining)->milliseconds();
        }
    }
}
