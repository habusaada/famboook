<?php

namespace App\Support\FamilyAuth;

use App\Enums\OtpFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Decoy activation challenges (docs/11 §30a). An activation start that is
 * denied — unknown National ID, ineligible Person, no trusted mobile, already
 * activated — still returns a challenge reference, and that reference must
 * behave like a real one: otherwise the second step would reveal what the
 * first one hides.
 *
 * A decoy is CACHE STATE ONLY: no Person, no User, no OTP row, no SMS. It
 * models what the public state machine shows — sends, cooldown, expiry,
 * attempts, lock, supersession — with the same policy values and the same
 * failure reasons as App\Support\FamilyAuth\OtpChallenges. No code is ever
 * correct for it.
 *
 * Keys hold a random reference or a keyed LOGIN_ID fingerprint — never a
 * National ID. Not atomic under concurrency: a decoy guards no resource, so
 * a lost update only makes a counter slightly generous.
 */
final class ActivationDecoys
{
    /**
     * How long a challenge reference — decoy or real — is recognised at all.
     * Longer than any challenge can live (3 sends × 5 minutes + the grant).
     */
    public const REFERENCE_TTL = 3600;

    /** A fresh decoy for this identifier; the previous one stops working. */
    public function create(string $loginKey): string
    {
        $this->supersede($loginKey);

        $uuid = (string) Str::uuid();
        $now = now()->getTimestamp();
        $this->put($uuid, [
            'login_key' => $loginKey,
            'sent_at' => $now,
            'send_count' => 1,
            'attempts' => 0,
            'expires_at' => $now + $this->setting('ttl_seconds'),
            'locked' => false,
            'superseded' => false,
            'gone_at' => $now + self::REFERENCE_TTL,
        ]);
        Cache::put(self::openKey($loginKey), $uuid, self::REFERENCE_TTL);

        return $uuid;
    }

    /** As a new real challenge supersedes the open one of its Person. */
    public function supersede(string $loginKey): void
    {
        $uuid = Cache::pull(self::openKey($loginKey));
        $state = is_string($uuid) ? $this->state($uuid) : null;
        if ($state !== null) {
            $this->put($uuid, [...$state, 'superseded' => true]);
        }
    }

    /** @return array<string, mixed>|null */
    public function state(string $uuid): ?array
    {
        $state = Cache::get(self::key($uuid));

        return is_array($state) ? $state : null;
    }

    /** A code entered against a decoy: always a failure, counted like a real one. */
    public function verify(string $uuid): OtpFailure
    {
        $state = $this->state($uuid);
        if ($failure = $this->unusable($state)) {
            return $failure;
        }

        $attempts = $state['attempts'] + 1;
        $this->put($uuid, [...$state, 'attempts' => $attempts, 'locked' => $attempts >= $this->setting('max_attempts')]);

        return OtpFailure::CODE_MISMATCH;
    }

    /**
     * A resend against a decoy: NULL when it is "sent" (nothing is), else the
     * reason a real challenge in the same state would give. $throttled is the
     * caller's cross-challenge ceiling for this identifier.
     */
    public function resend(string $uuid, bool $throttled): ?OtpFailure
    {
        $state = $this->state($uuid);
        if ($failure = $this->unusable($state)) {
            return $failure;
        }
        if ($state['send_count'] >= $this->setting('max_sends')) {
            return OtpFailure::SEND_LIMIT;
        }
        if ($this->cooldownRemaining($uuid) > 0) {
            return OtpFailure::COOLDOWN;
        }
        if ($throttled) {
            return OtpFailure::THROTTLED;
        }

        $now = now()->getTimestamp();
        $this->put($uuid, [
            ...$state,
            'send_count' => $state['send_count'] + 1,
            'sent_at' => $now,
            'expires_at' => $now + $this->setting('ttl_seconds'),
        ]);

        return null;
    }

    public function locked(string $uuid): bool
    {
        return ($this->state($uuid)['locked'] ?? false) === true;
    }

    public function sendCount(string $uuid): int
    {
        return (int) ($this->state($uuid)['send_count'] ?? 0);
    }

    public function cooldownRemaining(string $uuid): int
    {
        $state = $this->state($uuid);

        return $state === null
            ? 0
            : max(0, $state['sent_at'] + $this->setting('resend_cooldown_seconds') - now()->getTimestamp());
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
        return 'family-activation-decoy|'.$uuid;
    }

    private static function openKey(string $loginKey): string
    {
        return 'family-activation-decoy-open|'.$loginKey;
    }

    private function setting(string $name): int
    {
        return (int) config("family_auth.otp.{$name}");
    }
}
