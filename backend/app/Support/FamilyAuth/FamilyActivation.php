<?php

namespace App\Support\FamilyAuth;

use App\Enums\ActivationDenial;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\MobileTrustDenial;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\FamilyAuthException;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\UserPersonLink;
use BackedEnum;

/**
 * The public steps of Family account activation before the password
 * (docs/11 §30a, docs/05 §53b): start, verify and resend. The completion —
 * the account itself — is App\Actions\ActivateFamilyAccountAction.
 *
 * This class holds only the ACTIVATION rule: who gets a real challenge — an
 * eligible household head with a valid CURRENT registered mobile who is not
 * yet activated, found by an exact match on the registry (no authentication
 * identity exists before activation). Everything a caller can observe — the
 * generic refusal, timers, ceilings, public errors — is FamilyOtpFlow,
 * shared with password reset. Why a start was denied is recorded as a
 * security event and never returned.
 *
 * FIRST SELF-ACTIVATION (FP-ADR-053/054): start answers an eligible
 * identifier with a confirmation and the MASKED current number
 * (05*****123), and every other identifier with ONE refusal
 * (ACTIVATION_REFUSED) — the reason is a security event, never an answer;
 * no decoy, no confirmation, no number. Send — after the user confirmed the
 * number — issues the code to that stored number, and ONLY a correct code
 * makes it the Person's TRUSTED, SELF_OTP mobile (OtpChallenges::verify). A
 * number already TRUSTED (e.g. a Staff grant) is used as it is, never
 * replaced. The caller can never name or replace a destination.
 *
 * No raw National ID reaches a log, an event, a cache key or a limiter key:
 * the identifier is used once for the registry lookup and otherwise only as
 * its keyed LOGIN_ID fingerprint.
 */
final class FamilyActivation
{
    public function __construct(
        private readonly FamilyAccessResolver $resolver,
        private readonly OtpChallenges $otp,
        private readonly FamilyOtpFlow $flow,
        private readonly FamilyAuthIdentities $identities,
        private readonly CurrentTrustedMobile $trustedMobile,
    ) {}

    /**
     * Step 1: no SMS — a confirmation and the masked current number, or
     * ACTIVATION_REFUSED.
     *
     * @param  string  $nationalId  nine digits, already through FamilyNationalId::normalize()
     * @return array{confirmation: string, masked_mobile: string}
     */
    public function start(#[\SensitiveParameter] string $nationalId): array
    {
        return $this->flow->prepare(
            OtpPurpose::ACTIVATION,
            $nationalId,
            FamilyAuthError::ACTIVATION_UNAVAILABLE,
            fn (string $loginKey) => $this->eligibleFor($nationalId, $loginKey),
        );
    }

    /**
     * Step 2: the user confirmed the masked number — send the code to it.
     *
     * @return array{challenge: string, resend_after_seconds: int, expires_in_seconds: int, can_resend: bool}
     */
    public function send(string $confirmation): array
    {
        return $this->flow->send(
            OtpPurpose::ACTIVATION,
            $confirmation,
            fn (int $personId, string $loginKey, string $masked) => $this->issueFor($personId, $loginKey, $masked),
        );
    }

    /** @return array{verified: true, grant_expires_in_seconds: int} */
    public function verify(string $reference, #[\SensitiveParameter] string $code): array
    {
        return $this->flow->verify(OtpPurpose::ACTIVATION, $reference, $code);
    }

    /** @return array{resend_after_seconds: int, expires_in_seconds: int, can_resend: bool} */
    public function resend(string $reference): array
    {
        return $this->flow->resend(OtpPurpose::ACTIVATION, $reference);
    }

    /**
     * The Person and current number when the identifier may activate; NULL
     * means "refuse" (the reason is recorded, never returned).
     *
     * @return array{person_id: int, mobile: string}|null
     */
    private function eligibleFor(#[\SensitiveParameter] string $nationalId, string $loginKey): ?array
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
        if ($denial = $this->refusal($person)) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, $denial, $person);

            return null;
        }

        return ['person_id' => $person->getKey(), 'mobile' => FamilyMobile::normalize($person->mobile)];
    }

    /**
     * The real challenge for a confirmed number — or a refusal. Everything is
     * decided again now: the Person still eligible, still this identifier,
     * and still the very number whose mask the user confirmed — a changed
     * number is never sent to. A change since the start is refused like a
     * refused start (ACTIVATION_REFUSED); an SMS ceiling is OTP_SEND_LIMIT.
     */
    private function issueFor(int $personId, string $loginKey, string $confirmedMask): AuthOtpChallenge
    {
        $person = Person::query()->find($personId);
        $digits = FamilyNationalId::normalize($person?->national_id);
        if ($person === null || $digits === null || ! hash_equals($loginKey, $this->identities->keyFor($digits))) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, ActivationDenial::NOT_FOUND, $person, $loginKey);

            throw new FamilyAuthException(FamilyAuthError::ACTIVATION_REFUSED);
        }
        if ($denial = $this->refusal($person)) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, $denial, $person);

            throw new FamilyAuthException(FamilyAuthError::ACTIVATION_REFUSED);
        }
        if (ActivationConfirmations::mask((string) FamilyMobile::normalize($person->mobile)) !== $confirmedMask) {
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, MobileTrustDenial::STALE, $person);

            throw new FamilyAuthException(FamilyAuthError::ACTIVATION_REFUSED);
        }

        // Trusted number: as it is. Otherwise the current registered number,
        // pending until a correct code proves it.
        $result = $this->otp->issue(OtpPurpose::ACTIVATION, $person, selfVerification: true);
        if ($result->challenge === null) {
            // No valid mobile any more, or an SMS ceiling.
            $this->denied(AuthSecurityEventType::ELIGIBILITY_DENIED, $result->failure, $person);

            throw new FamilyAuthException($result->failure === OtpFailure::THROTTLED ? FamilyAuthError::OTP_SEND_LIMIT : FamilyAuthError::ACTIVATION_REFUSED);
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

    /** Why this Person may not activate now, or NULL. */
    private function refusal(Person $person): ?BackedEnum
    {
        return $this->resolver->headEligibility($person)
            ?? (UserPersonLink::query()->current()->where('person_id', $person->getKey())->exists() ? ActivationDenial::ALREADY_LINKED : null)
            ?? (FamilyMobile::normalize($person->mobile) === null ? MobileTrustDenial::NO_VALID_MOBILE : null)
            // Staff revoked the trust: self-verification never undoes that.
            ?? ($this->trustedMobile->for($person)->denial === MobileTrustDenial::REVOKED ? MobileTrustDenial::REVOKED : null);
    }

    private function denied(AuthSecurityEventType $type, BackedEnum $reason, ?Person $person, ?string $loginKey = null): void
    {
        // The earlier code of this Person dies, as it would for a new
        // challenge: a refused start supersedes like a real one.
        if ($person !== null) {
            $this->otp->supersede($person, OtpPurpose::ACTIVATION);
        }
        AuthSecurityLog::record($type, AuthSecurityEventOutcome::DENIED, $reason, person: $person, loginKey: $loginKey);
    }
}
