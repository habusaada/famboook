<?php

namespace App\Actions\ContactUpdate;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\ContactUpdateDenial;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyAuthError;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\FamilyAuthException;
use App\Models\AuthOtpChallenge;
use App\Models\ChangeRequest;
use App\Models\Person;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\ContactUpdate\ContactUpdateVerification;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\MobileTrusts;
use App\Support\FamilyAuth\OtpChallenges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completes a CONTACT_UPDATE (docs/11 FP-ADR-079): the code(s) of the
 * request are verified, then ONE transaction, in the engine's lock order
 * (request → Family → Person):
 *
 *   still SUBMITTED, still this Family's → data rules, preconditions, base
 *   fingerprint → consume the proposed-number grant (and, R2, the old
 *   trusted number's) → APPROVED by SYSTEM → UpdatePersonAction (the
 *   previous trust becomes STALE) → R2 only: the new number becomes the
 *   head's TRUSTED mobile (SELF_OTP_TRANSFER) → APPLIED by SYSTEM →
 *   CHANGE_REQUEST_APPLIED and CONTACT_MOBILE_CHANGED.
 *
 * All or nothing. Each code is verified in its own transaction first, so a
 * wrong code always counts (5, then locked). A request that can no longer
 * succeed — a locked or unusable code, a changed registry, a passed window
 * — is closed by the system after the rollback. A completed request is a
 * replay: nothing runs again. A member's number never carries trust; the
 * code proves access to the number, not identity.
 */
class CompleteContactUpdateAction
{
    public function __construct(
        private readonly OtpChallenges $otp,
        private readonly CloseContactUpdateAction $close,
        private readonly ChangeRequestTypes $types,
    ) {}

    /** @return array{outcome: ChangeRequestOutcome, trust_transferred: bool} */
    public function handle(FamilyAccessResult $context, ChangeRequest $request, #[\SensitiveParameter] string $code, #[\SensitiveParameter] ?string $oldCode): array
    {
        ChangeRequestActors::ownedBy($request, $context);
        if ($request->type !== ChangeRequestType::CONTACT_UPDATE) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
        $request->refresh();
        if ($request->status === ChangeRequestStatus::APPLIED) {
            return ['outcome' => new ChangeRequestOutcome($request, replayed: true), 'trust_transferred' => self::transferred($request)];
        }
        if ($request->status !== ChangeRequestStatus::SUBMITTED) {
            throw ChangeRequestWorkflow::invalidTransition();
        }
        if (CloseContactUpdateAction::expired($request)) {
            $this->close->handle($request, ContactUpdateDenial::EXPIRED);
            throw new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_EXPIRED);
        }

        $transfer = (bool) ($request->submitted_data['trust_transfer'] ?? false);
        if ($transfer && ($oldCode === null || $oldCode === '')) {
            throw ValidationException::withMessages(['old_code' => 'أدخل رمز التحقق المرسل إلى رقمك الحالي.']);
        }
        $new = $this->verified($request, OtpPurpose::CONTACT_NEW_MOBILE, $code, 'code');
        $old = $transfer ? $this->verified($request, OtpPurpose::CONTACT_OLD_MOBILE, (string) $oldCode, 'old_code') : null;

        try {
            $trusted = DB::transaction(fn () => $this->apply($context, $request, $new, $old));
        } catch (ChangeRequestException|ValidationException $e) {
            $this->close->handle($request, ContactUpdateDenial::NO_LONGER_APPLICABLE);
            throw $e instanceof ValidationException ? $e : new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_FAILED);
        } catch (ContactUpdateFailure) {
            $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);
            throw new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_FAILED);
        }

        return ['outcome' => new ChangeRequestOutcome($request->refresh()), 'trust_transferred' => $trusted];
    }

    /**
     * Verifies one code of the request (its own transaction: a wrong code
     * always counts) unless it is already verified. Returns the challenge.
     */
    private function verified(ChangeRequest $request, OtpPurpose $purpose, #[\SensitiveParameter] string $code, string $field): AuthOtpChallenge
    {
        $challenge = ContactUpdateVerification::openChallenge($request, $purpose);
        if ($challenge === null) {
            $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);
            throw new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_FAILED);
        }
        if ($challenge->verified_at !== null) {
            return $challenge;
        }

        $result = $this->otp->verify($challenge->uuid, $purpose, $code);
        if ($result->succeeded()) {
            return $challenge->refresh();
        }

        switch ($result->failure) {
            case OtpFailure::CODE_MISMATCH:
                if ($challenge->refresh()->locked_at !== null) {
                    $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);
                    throw new FamilyAuthException(FamilyAuthError::OTP_LOCKED);
                }
                throw ValidationException::withMessages([$field => 'رمز التحقق غير صحيح.']);
            case OtpFailure::EXPIRED:
                // Still resendable while the request's window is open.
                throw new FamilyAuthException(FamilyAuthError::OTP_EXPIRED);
            default:
                $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);
                throw new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_FAILED);
        }
    }

    /** The one transaction; returns whether the trust moved to the new number. */
    private function apply(FamilyAccessResult $context, ChangeRequest $request, AuthOtpChallenge $new, ?AuthOtpChallenge $old): bool
    {
        $locked = ChangeRequestWorkflow::lock($request);
        ChangeRequestActors::ownedBy($locked, $context);
        if ($locked->status !== ChangeRequestStatus::SUBMITTED || $locked->type !== ChangeRequestType::CONTACT_UPDATE) {
            throw new ContactUpdateFailure;
        }
        $handler = $this->types->handler($locked->type);
        $target = ChangeRequestWorkflow::lockCanonical($locked);
        ChangeRequestWorkflow::verify($handler, $locked, $target);
        ChangeRequestWorkflow::assertBaseUnchanged($handler, $locked, $target);

        $user = $context->user;
        $person = Person::query()->findOrFail($locked->person_id);
        if (! $this->otp->consume($new->uuid, OtpPurpose::CONTACT_NEW_MOBILE, $person, $user)->succeeded()) {
            throw new ContactUpdateFailure;
        }
        if ($old !== null && ! $this->otp->consume($old->uuid, OtpPurpose::CONTACT_OLD_MOBILE, $context->person, $user)->succeeded()) {
            throw new ContactUpdateFailure;
        }

        $locked->forceFill(['status' => ChangeRequestStatus::APPROVED, 'approved_by' => null, 'approved_at' => now()])->save();
        WorkflowEventRecorder::record($locked, ChangeRequestStatus::SUBMITTED, WorkflowEventType::APPROVED, WorkflowActorSide::SYSTEM, null);

        // The head made the change; the registry write is theirs.
        $handler->apply($locked, $target, $user->getKey());

        $transferred = false;
        if ($old !== null) {
            $person->refresh();
            if (MobileTrusts::transferAfterContactChange($person, (string) $locked->submitted_data['mobile'], $user) === null) {
                throw new ContactUpdateFailure;
            }
            $transferred = true;
        }

        $locked->forceFill(['status' => ChangeRequestStatus::APPLIED, 'applied_by' => null, 'applied_at' => now()])->save();
        WorkflowEventRecorder::record($locked, ChangeRequestStatus::APPROVED, WorkflowEventType::APPLIED, WorkflowActorSide::SYSTEM, null);
        FamilyActivityLog::record($locked->family_id, FamilyActivityType::CHANGE_REQUEST_APPLIED, $locked, null, [
            'request_type' => $locked->type->value,
        ]);
        AuthOtpChallenge::supersedeOpenForChangeRequest($locked->getKey());
        AuthSecurityLog::record(
            AuthSecurityEventType::CONTACT_MOBILE_CHANGED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $person, user: $user, link: $context->link,
            metadata: ['self' => (bool) ($locked->submitted_data['self'] ?? false), 'trust_transferred' => $transferred],
        );

        return $transferred;
    }

    /** Whether an applied request moved the trust (read back from its record). */
    private static function transferred(ChangeRequest $request): bool
    {
        return (bool) ($request->submitted_data['trust_transfer'] ?? false);
    }
}
