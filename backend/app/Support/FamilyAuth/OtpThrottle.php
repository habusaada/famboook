<?php

namespace App\Support\FamilyAuth;

use App\Models\Person;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Cross-challenge abuse control for OTP SMS (docs/11 §30a): ceilings per
 * Person, per destination, per IP and globally, checked before EVERY send
 * (a first send and every resend). The exact per-challenge rules — cooldown,
 * send count, verification attempts — live in the challenge row, not here.
 *
 * Keys never contain a raw identifier: the Person by internal id, the
 * destination by its keyed MOBILE fingerprint, the IP as a SHA-256 digest.
 * All ceilings come from config('family_auth.throttle') and are
 * environment-overridable.
 *
 * FAILS CLOSED: when the limiter's storage cannot be read or written, no
 * SMS is sent. Increments use the cache store's atomic increment; under
 * heavy concurrency the ceilings are approximate (the per-challenge limits
 * stay exact), and no stronger distributed guarantee is claimed.
 */
final class OtpThrottle
{
    public const HOUR = 3600;

    public const DAY = 86400;

    /**
     * Whether one more SMS may be sent. When it may, the attempt is counted
     * on every dimension — whether or not the delivery then succeeds.
     */
    public function attempt(Person $person, string $mobileFingerprint, ?string $ip): bool
    {
        try {
            $limits = $this->limits($person, $mobileFingerprint, $ip);

            foreach ($limits as [$key, $max]) {
                if ($max < 1 || RateLimiter::tooManyAttempts($key, $max)) {
                    return false;
                }
            }
            foreach ($limits as [$key, , $decay]) {
                RateLimiter::hit($key, $decay);
            }

            return true;
        } catch (Throwable) {
            // Abuse-control state is unknown: refuse. Logged without a value.
            Log::error('OTP throttle unavailable: no SMS was sent.');

            return false;
        }
    }

    /**
     * READ-ONLY: whether a send asked for from $ip would now be refused by a
     * ceiling that does not depend on whose phone it is — the IP's or the
     * global one. Nothing is counted.
     *
     * Decoy resends use it (FamilyOtpFlow): a real resend is refused once one
     * of these is reached, so a decoy must be too, or the refusal would tell
     * a real challenge from a decoy. The Person ceilings are mirrored per
     * identifier by FamilyOtpFlow itself; the destination ceilings CANNOT be
     * mirrored — a decoy has no destination, and inventing one would be
     * worse. Fails closed like attempt().
     */
    public function sharedCeilingReached(?string $ip): bool
    {
        try {
            $limits = [[self::key('global', 'all', 'hour'), self::max('global.hour')]];
            if ($ip !== null && $ip !== '') {
                $limits[] = [self::key('ip', hash('sha256', $ip), 'hour'), self::max('ip.hour')];
            }
            foreach ($limits as [$key, $max]) {
                if ($max < 1 || RateLimiter::tooManyAttempts($key, $max)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            Log::error('OTP throttle unavailable: no SMS was sent.');

            return true;
        }
    }

    /**
     * Every ceiling that applies, as [key, max, window seconds].
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public function limits(Person $person, string $mobileFingerprint, ?string $ip): array
    {
        $limits = [
            [self::key('person', (string) $person->getKey(), 'hour'), self::max('person.hour'), self::HOUR],
            [self::key('person', (string) $person->getKey(), 'day'), self::max('person.day'), self::DAY],
            [self::key('destination', $mobileFingerprint, 'hour'), self::max('destination.hour'), self::HOUR],
            [self::key('destination', $mobileFingerprint, 'day'), self::max('destination.day'), self::DAY],
            [self::key('global', 'all', 'hour'), self::max('global.hour'), self::HOUR],
        ];
        if ($ip !== null && $ip !== '') {
            $limits[] = [self::key('ip', hash('sha256', $ip), 'hour'), self::max('ip.hour'), self::HOUR];
        }

        return $limits;
    }

    private static function key(string $dimension, string $subject, string $window): string
    {
        return "family-otp|{$dimension}|{$subject}|{$window}";
    }

    private static function max(string $name): int
    {
        return (int) config("family_auth.throttle.{$name}");
    }
}
