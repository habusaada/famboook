<?php

namespace App\Support\FamilyAuth;

use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Decoy OTP challenges (docs/11 §30a). A public start that is denied — an
 * unknown National ID, an ineligible Person, no trusted mobile, an account
 * that already exists (activation) or does not (password reset) — still
 * returns a challenge reference, and that reference must behave like a real
 * one: otherwise the second step would reveal what the first one hides.
 *
 * A decoy is CACHE STATE ONLY: no Person, no User, no OTP row, no SMS. It
 * models what the public state machine shows — sends, cooldown, expiry,
 * attempts, lock, supersession — with the same policy values and the same
 * failure reasons as App\Support\FamilyAuth\OtpChallenges. No code is ever
 * correct for it.
 *
 * PURPOSE-BOUND, like a real challenge: the purpose is part of the state and
 * every read names it, so an ACTIVATION decoy does not exist for
 * PASSWORD_RESET and the reverse. "One open challenge per identifier" is
 * kept per purpose, as it is per Person and purpose for real challenges.
 *
 * CONCURRENCY PARITY (PWA-1I). A real challenge is row-locked, so parallel
 * requests against it are exact; a decoy must be exact too, or parallel
 * requests would tell the two apart. Nothing that parallel requests change
 * is a read-modify-write of the state record:
 *
 *   attempts     an atomic counter of its own (Cache::increment)
 *   superseded   a flag of its own, only ever set
 *   resend       one atomic claim per send (Cache::add): exactly one of
 *                parallel resends wins, the others see the cooldown
 *
 * The state record itself is written at creation and by the single winner of
 * a resend claim only.
 *
 * Keys hold a random reference or a keyed LOGIN_ID fingerprint — never a
 * National ID.
 */
final class ChallengeDecoys
{
    /**
     * How long a challenge reference — decoy or real — is recognised at all.
     * Longer than any challenge can live (3 sends × 5 minutes + the grant).
     */
    public const REFERENCE_TTL = 3600;

    /** A fresh decoy for this purpose and identifier; the previous one stops working. */
    public function create(OtpPurpose $purpose, string $loginKey): string
    {
        $this->supersede($purpose, $loginKey);

        $uuid = (string) Str::uuid();
        $now = now()->getTimestamp();
        $this->put($uuid, [
            'purpose' => $purpose->value,
            'login_key' => $loginKey,
            'sent_at' => $now,
            'send_count' => 1,
            'expires_at' => $now + $this->setting('ttl_seconds'),
            'gone_at' => $now + self::REFERENCE_TTL,
        ]);
        Cache::put(self::attemptsKey($uuid), 0, self::REFERENCE_TTL);
        Cache::put(self::openKey($purpose, $loginKey), $uuid, self::REFERENCE_TTL);

        return $uuid;
    }

    /** As a new real challenge supersedes the open one of its Person and purpose. */
    public function supersede(OtpPurpose $purpose, string $loginKey): void
    {
        $uuid = Cache::pull(self::openKey($purpose, $loginKey));
        if (is_string($uuid) && $this->state($purpose, $uuid) !== null) {
            Cache::put(self::supersededKey($uuid), true, self::REFERENCE_TTL);
        }
    }

    /**
     * The decoy behind a reference — only for the purpose it was created
     * for. For any other purpose it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function state(OtpPurpose $purpose, string $uuid): ?array
    {
        $state = Cache::get(self::key($uuid));
        if (! is_array($state) || ($state['purpose'] ?? null) !== $purpose->value) {
            return null;
        }
        $attempts = (int) Cache::get(self::attemptsKey($uuid), 0);

        return [
            ...$state,
            'attempts' => $attempts,
            'locked' => $attempts >= $this->setting('max_attempts'),
            'superseded' => Cache::get(self::supersededKey($uuid)) === true,
        ];
    }

    /**
     * A code entered against a decoy: always a failure, counted like a real
     * one. The count is atomic and each attempt is judged by ITS OWN count,
     * never by a later re-read: of parallel attempts, those numbered below
     * the limit are CODE_MISMATCH and the one that reaches it and every later
     * one are LOCKED — the answers a row-locked real challenge gives.
     */
    public function verify(OtpPurpose $purpose, string $uuid): OtpFailure
    {
        $state = $this->state($purpose, $uuid);
        if ($failure = $this->unusable($state)) {
            return $failure;
        }

        $attempts = Cache::increment(self::attemptsKey($uuid));
        if (! is_int($attempts)) {
            // The counter is gone with the reference.
            return OtpFailure::NOT_FOUND;
        }

        return $attempts >= $this->setting('max_attempts') ? OtpFailure::LOCKED : OtpFailure::CODE_MISMATCH;
    }

    /**
     * A resend against a decoy: NULL when it is "sent" (nothing is), else the
     * reason a real challenge in the same state would give. $throttled is the
     * caller's cross-challenge ceiling for this identifier and request.
     */
    public function resend(OtpPurpose $purpose, string $uuid, bool $throttled): ?OtpFailure
    {
        $state = $this->state($purpose, $uuid);
        if ($failure = $this->unusable($state)) {
            return $failure;
        }
        if ($state['send_count'] >= $this->setting('max_sends')) {
            return OtpFailure::SEND_LIMIT;
        }
        if ($this->cooldownRemaining($purpose, $uuid) > 0) {
            return OtpFailure::COOLDOWN;
        }
        if ($throttled) {
            return OtpFailure::THROTTLED;
        }

        // One winner per send: a parallel resend that read the same state
        // loses the claim and sees the cooldown, as on a row-locked challenge.
        $now = now()->getTimestamp();
        if (! Cache::add(self::resendKey($uuid, $state['send_count']), $now, self::REFERENCE_TTL)) {
            return OtpFailure::COOLDOWN;
        }
        $this->put($uuid, [
            ...array_diff_key($state, array_flip(['attempts', 'locked', 'superseded'])),
            'send_count' => $state['send_count'] + 1,
            'sent_at' => $now,
            'expires_at' => $now + $this->setting('ttl_seconds'),
        ]);

        return null;
    }

    /** Whether the decoy can no longer take a code: locked or superseded. */
    public function dead(OtpPurpose $purpose, string $uuid): bool
    {
        $state = $this->state($purpose, $uuid);

        return $state !== null && ($state['locked'] || $state['superseded']);
    }

    public function locked(OtpPurpose $purpose, string $uuid): bool
    {
        return ($this->state($purpose, $uuid)['locked'] ?? false) === true;
    }

    public function sendCount(OtpPurpose $purpose, string $uuid): int
    {
        return (int) ($this->state($purpose, $uuid)['send_count'] ?? 0);
    }

    /**
     * Seconds until the next resend may be sent. The latest send is the
     * later of the state's and of a resend claim just won by a parallel
     * request, whose state write may not be visible yet.
     */
    public function cooldownRemaining(OtpPurpose $purpose, string $uuid): int
    {
        $state = $this->state($purpose, $uuid);
        if ($state === null) {
            return 0;
        }
        $claimed = Cache::get(self::resendKey($uuid, $state['send_count']));
        $sentAt = max($state['sent_at'], is_int($claimed) ? $claimed : 0);

        return max(0, $sentAt + $this->setting('resend_cooldown_seconds') - now()->getTimestamp());
    }

    /**
     * The order OtpChallenges applies: superseded, locked, then expired.
     *
     * @param  array<string, mixed>|null  $state
     */
    private function unusable(?array $state): ?OtpFailure
    {
        return match (true) {
            $state === null => OtpFailure::NOT_FOUND,
            $state['superseded'] => OtpFailure::SUPERSEDED,
            $state['locked'] => OtpFailure::LOCKED,
            now()->getTimestamp() >= $state['expires_at'] => OtpFailure::EXPIRED,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $state */
    private function put(string $uuid, array $state): void
    {
        $remaining = $state['gone_at'] - now()->getTimestamp();
        if ($remaining > 0) {
            Cache::put(self::key($uuid), $state, $remaining);
        }
    }

    private static function key(string $uuid): string
    {
        return 'family-auth-decoy|'.$uuid;
    }

    private static function attemptsKey(string $uuid): string
    {
        return 'family-auth-decoy-attempts|'.$uuid;
    }

    private static function supersededKey(string $uuid): string
    {
        return 'family-auth-decoy-superseded|'.$uuid;
    }

    private static function resendKey(string $uuid, int $sendCount): string
    {
        return "family-auth-decoy-resend|{$uuid}|{$sendCount}";
    }

    private static function openKey(OtpPurpose $purpose, string $loginKey): string
    {
        return "family-auth-decoy-open|{$purpose->value}|{$loginKey}";
    }

    private function setting(string $name): int
    {
        return (int) config("family_auth.otp.{$name}");
    }
}
