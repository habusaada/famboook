<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use BackedEnum;

/**
 * The public steps of a Family password reset before the new password
 * (docs/11 §30a, docs/05 §53b): start, verify and resend. The completion is
 * App\Actions\ResetFamilyPasswordAction.
 *
 * This class holds only the PASSWORD RESET rule: who gets a real challenge —
 * an activated account, found like a login (National ID → keyed fingerprint →
 * ACTIVE Family Auth Identity → User; never the registry field), that has a
 * Family context right now and whose Person has a TRUSTED current mobile. An
 * account that could not enter /family gets no SMS. Everything a caller can
 * observe — the generic answer, decoys, timers, ceilings, public errors — is
 * FamilyOtpFlow, shared with activation. Why a start was denied is recorded
 * as a security event and never returned.
 */
final class FamilyPasswordReset
{
    public function __construct(
        private readonly FamilyAuthIdentities $identities,
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
            OtpPurpose::PASSWORD_RESET,
            $nationalId,
            FamilyAuthError::PASSWORD_RESET_UNAVAILABLE,
            fn (string $loginKey) => $this->issueFor($nationalId, $loginKey),
        );
    }

    /** @return array{verified: true, grant_expires_in_seconds: int} */
    public function verify(string $reference, #[\SensitiveParameter] string $code): array
    {
        return $this->flow->verify(OtpPurpose::PASSWORD_RESET, $reference, $code);
    }

    /** @return array{resend_after_seconds: int, expires_in_seconds: int, can_resend: bool} */
    public function resend(string $reference): array
    {
        return $this->flow->resend(OtpPurpose::PASSWORD_RESET, $reference);
    }

    /** The real challenge when the account may reset; NULL means "answer with a decoy". */
    private function issueFor(#[\SensitiveParameter] string $nationalId, string $loginKey): ?AuthOtpChallenge
    {
        $identity = $this->identities->findByNationalIdInput($nationalId);
        $user = $identity?->user;
        if ($user === null) {
            $this->denied(LoginDenial::UNKNOWN_IDENTIFIER, $loginKey);

            return null;
        }

        // The same rule as login: no Family context, no reset.
        $context = $this->resolver->familyContext($user);
        $denial = match (true) {
            ! $context->hasFamilyContext() => $context->denial,
            ! $context->authIdentity->is($identity) => LoginDenial::IDENTITY_MISMATCH,
            default => null,
        };
        if ($denial !== null) {
            $this->denied($denial, $loginKey, $user);

            return null;
        }

        $result = $this->otp->issue(OtpPurpose::PASSWORD_RESET, $context->person, $user);
        if ($result->challenge === null) {
            // No trusted mobile, or an SMS ceiling.
            $this->denied($result->failure, $loginKey, $user);

            return null;
        }

        // DELIVERY_FAILED keeps its real challenge: the public answer is the
        // same, and OtpChallenges already recorded the failed send.
        AuthSecurityLog::record(
            AuthSecurityEventType::PASSWORD_RESET_REQUESTED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $context->person, user: $user, link: $context->link,
            otpChallengeUuid: $result->challenge->uuid,
        );

        return $result->challenge;
    }

    private function denied(BackedEnum $reason, string $loginKey, ?User $user = null): void
    {
        // The earlier reset code of this account dies, as it would for a new
        // challenge: a decoy start supersedes like a real one.
        $person = $user === null ? null : $this->personOf($user);
        if ($person !== null) {
            $this->otp->supersede($person, OtpPurpose::PASSWORD_RESET);
        }
        AuthSecurityLog::record(
            AuthSecurityEventType::PASSWORD_RESET_REQUESTED,
            AuthSecurityEventOutcome::DENIED,
            $reason,
            person: $person, user: $user, loginKey: $loginKey,
        );
    }

    /** The Person of the account's latest link, whatever the link's state. */
    private function personOf(User $user): ?Person
    {
        $link = UserPersonLink::query()->where('user_id', $user->getKey())->latest('id')->first();

        return $link === null ? null : Person::withTrashed()->find($link->person_id);
    }
}
