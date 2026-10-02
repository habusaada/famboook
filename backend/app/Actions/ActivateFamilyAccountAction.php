<?php

namespace App\Actions;

use App\Enums\ActivationDenial;
use App\Enums\ActivationError;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\ActivationException;
use App\Exceptions\ActivationFailure;
use App\Exceptions\FamilyIdentityException;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use App\Support\FamilyAuth\ActivationDecoys;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyActivation;
use App\Support\FamilyAuth\OtpChallenges;
use Illuminate\Support\Facades\DB;

/**
 * Completes a Family account activation (docs/11 §30a, docs/05 §53b): the
 * one transaction that turns a verified ACTIVATION OTP grant and a password
 * into a family-side account.
 *
 *   lock the Person → consume the verified grant → re-check eligibility and
 *   "no current link" → NEW User (email null, name = a snapshot of the
 *   Person's name, hashed password, active) → FAMILY_USER only →
 *   EstablishFamilyIdentityAction (ACTIVE link + ACTIVE identity) →
 *   ACTIVATION_COMPLETED
 *
 * Everything commits or rolls back together: a failure leaves no user, no
 * role, no link, no identity, and the grant unconsumed.
 *
 * It ALWAYS creates a new User. It never looks one up, so a Staff account can
 * never receive FAMILY_USER; a Person whose earlier link was ENDED gets a new
 * account. The Person lock (also taken by OtpChallenges::issue and
 * EstablishFamilyIdentityAction, in the same order: Person, then challenge)
 * serializes concurrent completions; the partial unique indexes on links and
 * identities are the backstop.
 *
 * The session is the caller's business (after the commit). Refusals carry the
 * public error only; the internal reason goes to auth_security_events.
 */
class ActivateFamilyAccountAction
{
    public function __construct(
        private readonly FamilyActivation $activation,
        private readonly ActivationDecoys $decoys,
        private readonly OtpChallenges $otp,
        private readonly FamilyAccessResolver $resolver,
        private readonly EstablishFamilyIdentityAction $establish,
    ) {}

    public function handle(string $reference, #[\SensitiveParameter] string $password): User
    {
        $challenge = $this->activation->realChallenge($reference);
        if ($challenge === null) {
            // A decoy can never have been verified: it answers as an
            // unverified real challenge in the same state would.
            $state = $this->decoys->state($reference);

            throw new ActivationException(
                $state !== null && ($state['superseded'] || $state['locked']) ? ActivationError::OTP_LOCKED : ActivationError::OTP_INVALID
            );
        }

        try {
            return DB::transaction(fn () => $this->activate($challenge, $password));
        } catch (ActivationFailure $failure) {
            // After the rollback, so the refusal itself is kept.
            AuthSecurityLog::record(
                AuthSecurityEventType::ACTIVATION_COMPLETED,
                AuthSecurityEventOutcome::FAILURE,
                $failure->reason,
                person: Person::withTrashed()->find($challenge->person_id),
                otpChallengeUuid: $challenge->uuid,
            );

            throw new ActivationException($failure->error);
        }
    }

    private function activate(AuthOtpChallenge $challenge, #[\SensitiveParameter] string $password): User
    {
        // One activation per Person at a time.
        /** @var Person|null $person */
        $person = Person::withTrashed()->whereKey($challenge->person_id)->lockForUpdate()->first();
        if ($person === null) {
            throw new ActivationFailure(ActivationError::ACTIVATION_FAILED, ActivationDenial::CHALLENGE_UNUSABLE);
        }

        // The grant first: without a verified challenge nothing below is
        // evaluated, so an unverified caller learns nothing about the Person.
        // (A later refusal rolls this consumption back.)
        $consumed = $this->otp->consume($challenge->uuid, OtpPurpose::ACTIVATION, $person);
        if (! $consumed->succeeded()) {
            throw new ActivationFailure(self::publicError($consumed->failure), $consumed->failure);
        }

        if ($denial = $this->resolver->headEligibility($person)) {
            throw new ActivationFailure(ActivationError::ACTIVATION_FAILED, $denial);
        }
        if (UserPersonLink::query()->current()->where('person_id', $person->getKey())->exists()) {
            throw new ActivationFailure(ActivationError::ACTIVATION_FAILED, ActivationDenial::ALREADY_LINKED);
        }

        // Always a NEW family-side account: never an existing User.
        $user = new User;
        $user->forceFill([
            // A snapshot only: the portal always shows the Person's name.
            'name' => $person->full_name,
            'email' => null,
            'password' => $password,
            'is_active' => true,
        ])->save();
        $user->assignRole(AccountSide::FAMILY_USER);

        try {
            $link = $this->establish->handle($user, $person);
        } catch (FamilyIdentityException) {
            throw new ActivationFailure(ActivationError::ACTIVATION_FAILED, ActivationDenial::IDENTITY_REFUSED);
        }

        AuthSecurityLog::record(
            AuthSecurityEventType::ACTIVATION_COMPLETED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $person, user: $user, link: $link,
            otpChallengeUuid: $challenge->uuid,
        );

        return $user;
    }

    private static function publicError(OtpFailure $failure): ActivationError
    {
        return match ($failure) {
            OtpFailure::GRANT_EXPIRED => ActivationError::GRANT_EXPIRED,
            OtpFailure::LOCKED, OtpFailure::SUPERSEDED => ActivationError::OTP_LOCKED,
            // Proved the phone, but the grant can no longer be used.
            OtpFailure::CONSUMED, OtpFailure::TRUST_NOT_CURRENT => ActivationError::ACTIVATION_FAILED,
            default => ActivationError::OTP_INVALID,
        };
    }
}
