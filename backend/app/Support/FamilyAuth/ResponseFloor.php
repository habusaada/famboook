<?php

namespace App\Support\FamilyAuth;

use Illuminate\Support\Sleep;

/**
 * A minimum response time for the public OTP steps of activation and
 * password reset (docs/11 §30a): start, verify, resend and complete. A real
 * challenge costs lookups, a locked transaction and security events; a decoy
 * costs a few cache operations — without a floor the difference would tell
 * the two apart. The SMS itself is sent after the response (A′) and no
 * longer counts; verify and complete are floored since PWA-1I.
 *
 * config family_auth.activation.min_response_ms (0 disables), one value for
 * every step. The default (400) is a development value; the Production value
 * is set from measurements on Production-like infrastructure (docs/08 §16a).
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
