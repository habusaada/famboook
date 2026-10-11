<?php

namespace App\Actions\ContactUpdate;

use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\ChangeRequestType;
use App\Enums\ContactUpdateDenial;
use App\Enums\FamilyAuthError;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\FamilyAuthException;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\OtpChallenges;
use App\Support\FamilyAuth\OtpResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The household head starts a mobile CONTACT_UPDATE (docs/11 FP-ADR-079):
 *
 *   resolve the member (member_ref, this Family only) → the head's OWN
 *   number needs the current password → a client_reference replay returns
 *   the existing request and sends nothing → an older open request for the
 *   member is closed (SUPERSEDED) → the request is SUBMITTED through the
 *   engine (SubmitChangeRequestAction, system-verified) → a code to the
 *   PROPOSED number and, when the head's own number is TRUSTED (R2), a code
 *   to that OLD number.
 *
 * Nothing in the registry changes here. If a code cannot be issued (the OTP
 * ceilings, a trust that is no longer current) the new request is closed
 * at once — no request waits without a code. SMS go out after the response
 * (sms.after-response); the delivery outcome never changes the answer.
 */
class StartContactUpdateAction
{
    public function __construct(
        private readonly SubmitChangeRequestAction $submit,
        private readonly CloseContactUpdateAction $close,
        private readonly OtpChallenges $otp,
        private readonly ChangeRequestTypes $types,
    ) {}

    /**
     * @param  array<string, mixed>  $input  member_ref and mobile
     * @return array{outcome: ChangeRequestOutcome, request: ChangeRequest}
     */
    public function handle(FamilyAccessResult $context, array $input, #[\SensitiveParameter] ?string $currentPassword, ?string $clientReference): array
    {
        // The type must exist for the engine before anything is read.
        $this->types->handler(ChangeRequestType::CONTACT_UPDATE);
        $membership = HouseholdMemberReference::resolve($context, is_string($input['member_ref'] ?? null) ? $input['member_ref'] : '')
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        $self = (bool) $membership->is_household_head;

        // The head's own number (R2 / R1): the account's current password first.
        if ($self) {
            $this->assertCurrentPassword($context, $currentPassword, $membership);
        }

        $submission = new ChangeRequestSubmission(ChangeRequestType::CONTACT_UPDATE, $input, null, $clientReference);
        $replay = $clientReference !== null && ChangeRequest::query()
            ->where('submitted_by', $context->user->getKey())
            ->where('client_reference', $clientReference)
            ->exists();
        if ($replay) {
            // The engine decides: the same request (replayed) or a conflict.
            $outcome = $this->submit->handle($context, $submission, systemVerified: true);

            return ['outcome' => $outcome, 'request' => $outcome->request];
        }

        $this->close->closeOpenFor($membership);
        $outcome = $this->submit->handle($context, $submission, systemVerified: true);
        $request = $outcome->request;

        $person = Person::query()->findOrFail($request->person_id);
        $this->issued($request, $this->otp->issueToProposedMobile($person, $context->user, $request));
        if ((bool) ($request->submitted_data['trust_transfer'] ?? false)) {
            $this->issued($request, $this->otp->issue(OtpPurpose::CONTACT_OLD_MOBILE, $context->person, $context->user, false, $request));
        }

        return ['outcome' => $outcome, 'request' => $request];
    }

    private function assertCurrentPassword(FamilyAccessResult $context, #[\SensitiveParameter] ?string $password, FamilyMembership $membership): void
    {
        if ($password === null || $password === '') {
            throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية مطلوبة لتغيير رقم جوالك.']);
        }
        $hash = $context->user->password;
        if (is_string($hash) && Hash::check($password, $hash)) {
            return;
        }

        AuthSecurityLog::record(
            AuthSecurityEventType::CONTACT_MOBILE_CHANGED,
            AuthSecurityEventOutcome::FAILURE,
            ContactUpdateDenial::WRONG_CURRENT_PASSWORD,
            person: Person::query()->find($membership->person_id), user: $context->user, link: $context->link,
            metadata: ['self' => true],
        );

        throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
    }

    /** A code that could not be issued closes the new request at once. */
    private function issued(ChangeRequest $request, OtpResult $result): void
    {
        // A deferred SMS is not known yet; only issuing refusals count here.
        if ($result->succeeded() || $result->failure === OtpFailure::DELIVERY_FAILED) {
            return;
        }
        $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);

        throw new FamilyAuthException($result->failure === OtpFailure::THROTTLED
            ? FamilyAuthError::TOO_MANY_REQUESTS
            : FamilyAuthError::CONTACT_UPDATE_FAILED);
    }
}
