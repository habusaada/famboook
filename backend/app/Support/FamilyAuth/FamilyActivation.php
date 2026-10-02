<?php

namespace App\Support\FamilyAuth;

use App\Enums\ActivationDenial;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\OtpPurpose;
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
 * eligible household head with a TRUSTED mobile who is not yet activated,
 * found by an exact match on the registry (no authentication identity exists
 * before activation). Everything a caller can observe — the generic answer,
 * decoys, timers, ceilings, public errors — is FamilyOtpFlow, shared with
 * password reset. Why a start was denied is recorded as a security event and
 * never returned. The OTP only ever goes to the Person's current trusted
 * mobile — the caller cannot name a destination.
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
    ) {}

    /**
     * @param  string  $nationalId  nine digits, already through FamilyNationalId::normalize()
     * @return array{challenge: string, resend_after_seconds: int, expires_in_seconds: int, can_resend: bool}
     */
    public function start(#[\SensitiveParameter] string $nationalId): array
    {
        return $this->flow->start(
            OtpPurpose::ACTIVATION,
            $nationalId,
            FamilyAuthError::ACTIVATION_UNAVAILABLE,
            fn (string $loginKey) => $this->issueFor($nationalId, $loginKey),
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
}
