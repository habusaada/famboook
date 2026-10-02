<?php

namespace App\Support\FamilyAuth;

use App\Enums\ActivationDenial;
use App\Enums\ActivationError;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\ActivationException;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\UserPersonLink;
use BackedEnum;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * The public steps of Family account activation before the password
 * (docs/11 §30a, docs/05 §53b): start, verify and resend. The completion —
 * the account itself — is App\Actions\ActivateFamilyAccountAction.
 *
 * ANTI-ENUMERATION. Every well-formed National ID gets the same answer from
 * start: a challenge reference and the same timers. Behind it is either a
 * real OTP challenge (an eligible household head with a TRUSTED mobile, not
 * yet activated) or a decoy (App\Support\FamilyAuth\ActivationDecoys), and
 * verify and resend treat the two alike. Why a start was denied is recorded
 * as a security event and never returned. The OTP only ever goes to the
 * Person's current trusted mobile — the caller cannot name a destination.
 *
 * No raw National ID reaches a log, an event, a cache key or a limiter key:
 * the identifier is used once for the registry lookup and otherwise only as
 * its keyed LOGIN_ID fingerprint.
 */
final class FamilyActivation
{
    public function __construct(
        private readonly FamilyAccessResolver $resolver,
        private readonly FamilyAuthIdentities $identities,
        private readonly OtpChallenges $otp,
        private readonly ActivationDecoys $decoys,
    ) {}

    /**
     * @param  string  $nationalId  nine digits, already through FamilyNationalId::normalize()
     * @return array{challenge: string, resend_after_seconds: int, expires_in_seconds: int, can_resend: bool}
     */
    public function start(#[\SensitiveParameter] string $nationalId): array
    {
        try {
            $loginKey = $this->identities->keyFor($nationalId);
        } catch (LogicException) {
            // No fingerprint key: nothing can be activated, for anyone.
            Log::error('Family activation unavailable: the Family Auth fingerprint key is missing.');

            throw new ActivationException(ActivationError::ACTIVATION_UNAVAILABLE);
        }

        // Per identifier, whether it exists or not: the refusal says nothing.
        $limit = 'family-activation|start-identifier|'.$loginKey;
        if (RateLimiter::tooManyAttempts($limit, (int) config('family_auth.activation.limits.start_identifier_hour'))) {
            throw new ActivationException(ActivationError::TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limit, 3600);

        // A new start makes the previous reference of this identifier
        // unusable — a decoy here, a real challenge inside issue().
        $this->decoys->supersede($loginKey);

        $challenge = $this->issueFor($nationalId, $loginKey);
        $reference = $challenge?->uuid ?? $this->decoys->create($loginKey);
        // A decoy "sends" too — unless the ceiling is reached, where a real
        // start would have sent nothing either.
        if ($challenge !== null || ! $this->sendsExhausted($loginKey)) {
            $this->countSend($loginKey);
        }

        return ['challenge' => $reference, ...$this->timers(sendCount: 1)];
    }

    /** @return array{verified: true, grant_expires_in_seconds: int} */
    public function verify(string $reference, #[\SensitiveParameter] string $code): array
    {
        if ($this->decoys->state($reference) !== null) {
            $failure = $this->decoys->verify($reference);

            throw $this->refused($failure, $this->decoys->locked($reference));
        }
        if ($this->realChallenge($reference) === null) {
            throw new ActivationException(ActivationError::OTP_INVALID);
        }

        $result = $this->otp->verify($reference, OtpPurpose::ACTIVATION, $code);
        if (! $result->succeeded()) {
            throw $this->refused($result->failure, $result->challenge?->locked_at !== null);
        }

        return ['verified' => true, 'grant_expires_in_seconds' => (int) config('family_auth.otp.grant_ttl_seconds')];
    }

    /** @return array{resend_after_seconds: int, expires_in_seconds: int, can_resend: bool} */
    public function resend(string $reference): array
    {
        $decoy = $this->decoys->state($reference);
        if ($decoy !== null) {
            $failure = $this->decoys->resend($reference, $this->sendsExhausted($decoy['login_key']));
            if ($failure !== null) {
                throw $this->refused($failure, false, $this->decoys->cooldownRemaining($reference));
            }
            $this->countSend($decoy['login_key']);

            return $this->timers($this->decoys->sendCount($reference));
        }

        $challenge = $this->realChallenge($reference);
        if ($challenge === null) {
            throw new ActivationException(ActivationError::OTP_INVALID);
        }

        $result = $this->otp->resend($reference, OtpPurpose::ACTIVATION);
        // A send that failed at the provider is still a send, publicly:
        // only an eligible identifier could ever report a delivery failure.
        if (! $result->succeeded() && $result->failure !== OtpFailure::DELIVERY_FAILED) {
            $wait = $challenge->last_sent_at->getTimestamp() + (int) config('family_auth.otp.resend_cooldown_seconds') - now()->getTimestamp();

            throw $this->refused($result->failure, false, max(0, $wait));
        }
        $this->countSend($this->loginKeyOf($challenge));

        return $this->timers($result->challenge->send_count);
    }

    /**
     * The real ACTIVATION challenge behind a reference, while the reference
     * is still recognised (decoys are forgotten after the same time).
     */
    public function realChallenge(string $reference): ?AuthOtpChallenge
    {
        $challenge = AuthOtpChallenge::query()->where('uuid', $reference)->first();

        return $challenge !== null
            && $challenge->purpose === OtpPurpose::ACTIVATION
            && $challenge->created_at->getTimestamp() + ActivationDecoys::REFERENCE_TTL > now()->getTimestamp()
                ? $challenge
                : null;
    }

    /** The internal OTP reason as its public error. */
    public function refused(OtpFailure $failure, bool $locked = false, ?int $cooldown = null): ActivationException
    {
        return match ($failure) {
            OtpFailure::CODE_MISMATCH => new ActivationException($locked ? ActivationError::OTP_LOCKED : ActivationError::OTP_INVALID),
            OtpFailure::LOCKED, OtpFailure::SUPERSEDED, OtpFailure::TRUST_NOT_CURRENT => new ActivationException(ActivationError::OTP_LOCKED),
            OtpFailure::EXPIRED => new ActivationException(ActivationError::OTP_EXPIRED),
            OtpFailure::COOLDOWN => new ActivationException(ActivationError::OTP_COOLDOWN, $cooldown),
            OtpFailure::SEND_LIMIT, OtpFailure::THROTTLED => new ActivationException(ActivationError::OTP_SEND_LIMIT),
            OtpFailure::GRANT_EXPIRED => new ActivationException(ActivationError::GRANT_EXPIRED),
            default => new ActivationException(ActivationError::OTP_INVALID),
        };
    }

    /** The real challenge when the identifier may activate; NULL means "answer with a decoy". */
    private function issueFor(#[\SensitiveParameter] string $nationalId, string $loginKey): ?AuthOtpChallenge
    {
        // Exact match on the stored value; persons.national_id is not unique.
        $persons = Person::query()->where('national_id', $nationalId)->limit(2)->get();
        if ($persons->isEmpty()) {
            $this->denied(AuthSecurityEventType::ACTIVATION_REQUESTED, ActivationDenial::NOT_FOUND, null, $loginKey);

            return null;
        }
        if ($persons->count() > 1) {
            AuthSecurityLog::record(AuthSecurityEventType::AMBIGUOUS_IDENTITY, AuthSecurityEventOutcome::DENIED, loginKey: $loginKey);

            return null;
        }

        /** @var Person $person */
        $person = $persons->first();
        if ($denial = $this->resolver->headEligibility($person)) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, $denial, $person);

            return null;
        }
        if (UserPersonLink::query()->current()->where('person_id', $person->getKey())->exists()) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, ActivationDenial::ALREADY_LINKED, $person);

            return null;
        }

        $result = $this->otp->issue(OtpPurpose::ACTIVATION, $person);
        if ($result->challenge === null) {
            // No trusted mobile, or an SMS ceiling.
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, $result->failure, $person);

            return null;
        }

        // DELIVERY_FAILED keeps its real challenge: the public answer is the
        // same, and OtpChallenges already recorded the failed send.
        AuthSecurityLog::record(
            AuthSecurityEventType::ACTIVATION_REQUESTED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $person,
            otpChallengeUuid: $result->challenge->uuid,
        );

        return $result->challenge;
    }

    private function denied(AuthSecurityEventType $type, BackedEnum $reason, ?Person $person, ?string $loginKey = null): void
    {
        // The earlier code of this Person dies, as it would for a new
        // challenge: a decoy start supersedes like a real one.
        if ($person !== null) {
            $this->otp->supersede($person, OtpPurpose::ACTIVATION);
        }
        AuthSecurityLog::record($type, AuthSecurityEventOutcome::DENIED, $reason, person: $person, loginKey: $loginKey);
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

    // Sends per identifier, real and decoy alike, against the per-Person SMS
    // ceilings — so a decoy stops "sending" where a real Person's SMS would
    // be throttled. OtpThrottle stays the only real SMS ceiling.

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
        return "family-activation|sends|{$loginKey}|{$window}";
    }

    private function loginKeyOf(AuthOtpChallenge $challenge): ?string
    {
        $digits = FamilyNationalId::normalize(Person::withTrashed()->find($challenge->person_id)?->national_id);

        return $digits === null ? null : $this->identities->keyFor($digits);
    }
}
