<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\FamilyAuthException;
use App\Exceptions\FamilyAuthFailure;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyOtpFlow;
use App\Support\FamilyAuth\FamilySessions;
use App\Support\FamilyAuth\OtpChallenges;
use Illuminate\Support\Facades\DB;

/**
 * Completes a Family password reset (docs/11 §30a, docs/05 §53b): the one
 * transaction that turns a verified PASSWORD_RESET OTP grant and a new
 * password into a changed password.
 *
 *   lock the Person → consume the verified grant (bound to this Person AND
 *   this User) → the account must still have its Family context, for the
 *   same Person → new password → EVERY session of the User ended →
 *   PASSWORD_RESET_COMPLETED
 *
 * Everything commits or rolls back together: on a failure the old password
 * stays, the grant stays unconsumed and no session is ended. Mobile trust is
 * not touched.
 *
 * The Person lock is taken first, then the challenge — the order
 * OtpChallenges::issue and the activation use. The session of the browser
 * that completes the reset is the caller's business, AFTER the commit: it is
 * created after the revocation, so it is never among the sessions ended
 * here. Refusals carry the public error only; the internal reason goes to
 * auth_security_events.
 */
class ResetFamilyPasswordAction
{
    public function __construct(
        private readonly FamilyOtpFlow $flow,
        private readonly OtpChallenges $otp,
        private readonly FamilyAccessResolver $resolver,
    ) {}

    public function handle(string $reference, #[\SensitiveParameter] string $password): User
    {
        $challenge = $this->flow->realChallenge(OtpPurpose::PASSWORD_RESET, $reference);
        if ($challenge === null) {
            throw $this->flow->withoutRealChallenge(OtpPurpose::PASSWORD_RESET, $reference);
        }

        try {
            return DB::transaction(fn () => $this->reset($challenge, $password));
        } catch (FamilyAuthFailure $failure) {
            // After the rollback, so the refusal itself is kept.
            AuthSecurityLog::record(
                AuthSecurityEventType::PASSWORD_RESET_COMPLETED,
                AuthSecurityEventOutcome::FAILURE,
                $failure->reason,
                person: Person::withTrashed()->find($challenge->person_id),
                user: User::find($challenge->user_id),
                otpChallengeUuid: $challenge->uuid,
            );

            throw new FamilyAuthException($failure->error);
        }
    }

    private function reset(AuthOtpChallenge $challenge, #[\SensitiveParameter] string $password): User
    {
        /** @var Person|null $person */
        $person = Person::withTrashed()->whereKey($challenge->person_id)->lockForUpdate()->first();
        /** @var User|null $user */
        $user = User::query()->whereKey($challenge->user_id)->lockForUpdate()->first();
        if ($person === null || $user === null) {
            throw new FamilyAuthFailure(FamilyAuthError::RESET_FAILED, LoginDenial::ACCOUNT_CHANGED);
        }

        // The grant first: without a verified challenge nothing below is
        // evaluated, so an unverified caller learns nothing about the account.
        // (A later refusal rolls this consumption back.)
        $consumed = $this->otp->consume($challenge->uuid, OtpPurpose::PASSWORD_RESET, $person, $user);
        if (! $consumed->succeeded()) {
            throw new FamilyAuthFailure(self::publicError($consumed->failure), $consumed->failure);
        }

        // The account must still be able to enter the portal — as this Person.
        $context = $this->resolver->familyContext($user);
        if (! $context->hasFamilyContext()) {
            throw new FamilyAuthFailure(FamilyAuthError::RESET_FAILED, $context->denial);
        }
        if (! $context->person->is($person)) {
            throw new FamilyAuthFailure(FamilyAuthError::RESET_FAILED, LoginDenial::ACCOUNT_CHANGED);
        }

        // Hashed by the model's cast; never stored or logged in plain text.
        $user->forceFill(['password' => $password])->save();

        // Every session that existed before this moment, and the remember token.
        FamilySessions::revoke($user, null, AuthSecurityEventType::PASSWORD_RESET_COMPLETED);

        AuthSecurityLog::record(
            AuthSecurityEventType::PASSWORD_RESET_COMPLETED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $person, user: $user, link: $context->link,
            otpChallengeUuid: $challenge->uuid,
        );

        return $user;
    }

    private static function publicError(OtpFailure $failure): FamilyAuthError
    {
        return match ($failure) {
            OtpFailure::GRANT_EXPIRED => FamilyAuthError::GRANT_EXPIRED,
            OtpFailure::LOCKED, OtpFailure::SUPERSEDED => FamilyAuthError::OTP_LOCKED,
            // Proved the phone, but the grant can no longer be used.
            OtpFailure::CONSUMED, OtpFailure::TRUST_NOT_CURRENT, OtpFailure::USER_MISMATCH, OtpFailure::PERSON_MISMATCH => FamilyAuthError::RESET_FAILED,
            default => FamilyAuthError::OTP_INVALID,
        };
    }
}
