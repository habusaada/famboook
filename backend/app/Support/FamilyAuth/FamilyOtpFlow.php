<?php

namespace App\Support\FamilyAuth;

use App\Enums\FamilyAuthError;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\FamilyAuthException;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * The public OTP steps shared by Family activation and password reset
 * (docs/11 §30a): start, verify and resend, for ONE purpose at a time.
 *
 * ANTI-ENUMERATION. Every well-formed National ID gets the same answer from
 * start: a challenge reference and the same timers. Behind it is either a
 * real OTP challenge of that purpose or a decoy of that purpose
 * (App\Support\FamilyAuth\ChallengeDecoys), and verify and resend treat the
 * two alike. Who gets a real challenge is the caller's rule (the $issue
 * callback of start); everything visible from outside is decided here, once,
 * so the two workflows cannot drift apart.
 *
 * A reference belongs to its purpose: an ACTIVATION reference — real or
 * decoy — is simply unknown to PASSWORD_RESET, and the reverse.
 *
 * No raw National ID reaches a cache key or a limiter key: only its keyed
 * LOGIN_ID fingerprint.
 */
final class FamilyOtpFlow
{
    /** Config section and key prefix of each purpose's request ceilings. */
    private const SLUGS = [
        OtpPurpose::ACTIVATION->value => ['activation', 'family-activation'],
        OtpPurpose::PASSWORD_RESET->value => ['password_reset', 'family-password-reset'],
    ];

    public function __construct(
        private readonly FamilyAuthIdentities $identities,
        private readonly OtpChallenges $otp,
        private readonly ChallengeDecoys $decoys,
    ) {}

    /**
     * @param  string  $nationalId  nine digits, already through FamilyNationalId::normalize()
     * @param  FamilyAuthError  $unavailable  the answer when no fingerprint key exists
     * @param  Closure(string): ?AuthOtpChallenge  $issue  given the login key, the real
     *                                                     challenge — or NULL for "answer with a decoy"
     * @return array{challenge: string, resend_after_seconds: int, expires_in_seconds: int, can_resend: bool}
     */
    public function start(OtpPurpose $purpose, #[\SensitiveParameter] string $nationalId, FamilyAuthError $unavailable, Closure $issue): array
    {
        try {
            $loginKey = $this->identities->keyFor($nationalId);
        } catch (LogicException) {
            // No fingerprint key: nothing can proceed, for anyone.
            Log::error('Family authentication unavailable: the Family Auth fingerprint key is missing.');

            throw new FamilyAuthException($unavailable);
        }

        // Per identifier, whether it exists or not: the refusal says nothing.
        [$config, $prefix] = self::SLUGS[$purpose->value];
        $limit = "{$prefix}|start-identifier|{$loginKey}";
        if (RateLimiter::tooManyAttempts($limit, (int) config("family_auth.{$config}.limits.start_identifier_hour"))) {
            throw new FamilyAuthException(FamilyAuthError::TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limit, 3600);

        // A new start makes the previous reference of this identifier and
        // purpose unusable — a decoy here, a real challenge inside issue().
        $this->decoys->supersede($purpose, $loginKey);

        $challenge = $issue($loginKey);
        $reference = $challenge?->uuid ?? $this->decoys->create($purpose, $loginKey);
        // A decoy "sends" too — unless the ceiling is reached, where a real
        // start would have sent nothing either.
        if ($challenge !== null || ! $this->sendsExhausted($loginKey)) {
            $this->countSend($loginKey);
        }

        return ['challenge' => $reference, ...$this->timers(sendCount: 1)];
    }

    /** @return array{verified: true, grant_expires_in_seconds: int} */
    public function verify(OtpPurpose $purpose, string $reference, #[\SensitiveParameter] string $code): array
    {
        if ($this->decoys->state($purpose, $reference) !== null) {
            // Judged by this attempt's own atomic count (LOCKED at the limit),
            // never by a re-read a parallel attempt may have moved.
            throw $this->refused($this->decoys->verify($purpose, $reference));
        }
        if ($this->realChallenge($purpose, $reference) === null) {
            throw new FamilyAuthException(FamilyAuthError::OTP_INVALID);
        }

        $result = $this->otp->verify($reference, $purpose, $code);
        if (! $result->succeeded()) {
            throw $this->refused($result->failure, $result->challenge?->locked_at !== null);
        }

        return ['verified' => true, 'grant_expires_in_seconds' => (int) config('family_auth.otp.grant_ttl_seconds')];
    }

    /** @return array{resend_after_seconds: int, expires_in_seconds: int, can_resend: bool} */
    public function resend(OtpPurpose $purpose, string $reference): array
    {
        $decoy = $this->decoys->state($purpose, $reference);
        if ($decoy !== null) {
            $failure = $this->decoys->resend($purpose, $reference, $this->sendsExhausted($decoy['login_key']));
            if ($failure !== null) {
                throw $this->refused($failure, false, $this->decoys->cooldownRemaining($purpose, $reference));
            }
            $this->countSend($decoy['login_key']);

            return $this->timers($this->decoys->sendCount($purpose, $reference));
        }

        $challenge = $this->realChallenge($purpose, $reference);
        if ($challenge === null) {
            throw new FamilyAuthException(FamilyAuthError::OTP_INVALID);
        }

        $result = $this->otp->resend($reference, $purpose);
        // A send that failed at the provider is still a send, publicly:
        // only an eligible identifier could ever report a delivery failure.
        if (! $result->succeeded() && $result->failure !== OtpFailure::DELIVERY_FAILED) {
            // The row as read under its lock: a parallel resend that just won
            // moved last_sent_at, and the cooldown counts from there.
            $wait = ($result->challenge ?? $challenge)->last_sent_at->getTimestamp() + (int) config('family_auth.otp.resend_cooldown_seconds') - now()->getTimestamp();

            throw $this->refused($result->failure, false, max(0, $wait));
        }
        $this->countSend($this->loginKeyOf($challenge));

        return $this->timers($result->challenge->send_count);
    }

    /**
     * The real challenge of this purpose behind a reference, while the
     * reference is still recognised (decoys are forgotten after the same
     * time). A challenge of another purpose is not found.
     */
    public function realChallenge(OtpPurpose $purpose, string $reference): ?AuthOtpChallenge
    {
        $challenge = AuthOtpChallenge::query()->where('uuid', $reference)->first();

        return $challenge !== null
            && $challenge->purpose === $purpose
            && $challenge->created_at->getTimestamp() + ChallengeDecoys::REFERENCE_TTL > now()->getTimestamp()
                ? $challenge
                : null;
    }

    /**
     * What a completion answers for a reference with no real challenge: a
     * decoy can never have been verified, so it answers as an unverified
     * real challenge in the same state would.
     */
    public function withoutRealChallenge(OtpPurpose $purpose, string $reference): FamilyAuthException
    {
        return new FamilyAuthException($this->decoys->dead($purpose, $reference) ? FamilyAuthError::OTP_LOCKED : FamilyAuthError::OTP_INVALID);
    }

    /** The internal OTP reason as its public error. */
    public function refused(OtpFailure $failure, bool $locked = false, ?int $cooldown = null): FamilyAuthException
    {
        return match ($failure) {
            OtpFailure::CODE_MISMATCH => new FamilyAuthException($locked ? FamilyAuthError::OTP_LOCKED : FamilyAuthError::OTP_INVALID),
            OtpFailure::LOCKED, OtpFailure::SUPERSEDED, OtpFailure::TRUST_NOT_CURRENT => new FamilyAuthException(FamilyAuthError::OTP_LOCKED),
            OtpFailure::EXPIRED => new FamilyAuthException(FamilyAuthError::OTP_EXPIRED),
            OtpFailure::COOLDOWN => new FamilyAuthException(FamilyAuthError::OTP_COOLDOWN, $cooldown),
            OtpFailure::SEND_LIMIT, OtpFailure::THROTTLED => new FamilyAuthException(FamilyAuthError::OTP_SEND_LIMIT),
            OtpFailure::GRANT_EXPIRED => new FamilyAuthException(FamilyAuthError::GRANT_EXPIRED),
            default => new FamilyAuthException(FamilyAuthError::OTP_INVALID),
        };
    }

    /** @return array{resend_after_seconds: int, expires_in_seconds: int, can_resend: bool} */
    private function timers(int $sendCount): array
    {
        return [
            'resend_after_seconds' => (int) config('family_auth.otp.resend_cooldown_seconds'),
            'expires_in_seconds' => (int) config('family_auth.otp.ttl_seconds'),
            'can_resend' => $sendCount < (int) config('family_auth.otp.max_sends'),
        ];
    }

    // Sends per identifier — real and decoy, ACTIVATION and PASSWORD_RESET
    // together — against the per-Person SMS ceilings, which are shared across
    // purposes too. So a decoy stops "sending" where a real Person's SMS
    // would be throttled. OtpThrottle stays the only real SMS ceiling.

    private function countSend(?string $loginKey): void
    {
        if ($loginKey !== null) {
            RateLimiter::hit(self::sendsKey($loginKey, 'hour'), OtpThrottle::HOUR);
            RateLimiter::hit(self::sendsKey($loginKey, 'day'), OtpThrottle::DAY);
        }
    }

    private function sendsExhausted(string $loginKey): bool
    {
        return RateLimiter::tooManyAttempts(self::sendsKey($loginKey, 'hour'), (int) config('family_auth.throttle.person.hour'))
            || RateLimiter::tooManyAttempts(self::sendsKey($loginKey, 'day'), (int) config('family_auth.throttle.person.day'));
    }

    private static function sendsKey(string $loginKey, string $window): string
    {
        return "family-otp-sends|{$loginKey}|{$window}";
    }

    private function loginKeyOf(AuthOtpChallenge $challenge): ?string
    {
        $digits = FamilyNationalId::normalize(Person::withTrashed()->find($challenge->person_id)?->national_id);

        return $digits === null ? null : $this->identities->keyFor($digits);
    }
}
