<?php

namespace App\Actions\ContactUpdate;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\ContactUpdateDenial;
use App\Enums\FamilyAuthError;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\FamilyAuthException;
use App\Models\ChangeRequest;
use App\Models\Person;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ContactUpdate\ContactUpdateVerification;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\OtpChallenges;
use App\Support\FamilyAuth\OtpResult;

/**
 * A new code for one destination of an open CONTACT_UPDATE (docs/11
 * FP-ADR-079): `new` — the proposed number, or `old` — the head's current
 * trusted number (R2 only). The OTP rules apply unchanged: 60 s cooldown,
 * at most 3 sends per code, the Person / destination / IP / global
 * ceilings. A code that merely expired is replaced by a fresh one while the
 * request's window is open; one that can no longer be used closes the
 * request.
 */
class ResendContactUpdateCodeAction
{
    public function __construct(
        private readonly OtpChallenges $otp,
        private readonly CloseContactUpdateAction $close,
    ) {}

    public function handle(FamilyAccessResult $context, ChangeRequest $request, string $target): ChangeRequest
    {
        ChangeRequestActors::ownedBy($request, $context);
        if ($request->type !== ChangeRequestType::CONTACT_UPDATE) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
        $request->refresh();
        if ($request->status !== ChangeRequestStatus::SUBMITTED) {
            throw ChangeRequestWorkflow::invalidTransition();
        }
        if (CloseContactUpdateAction::expired($request)) {
            $this->close->handle($request, ContactUpdateDenial::EXPIRED);
            throw new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_EXPIRED);
        }
        $old = $target === 'old';
        if ($old && ! (bool) ($request->submitted_data['trust_transfer'] ?? false)) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
        $purpose = $old ? OtpPurpose::CONTACT_OLD_MOBILE : OtpPurpose::CONTACT_NEW_MOBILE;

        $challenge = ContactUpdateVerification::openChallenge($request, $purpose);
        $result = $challenge === null
            ? OtpResult::failed(OtpFailure::NOT_FOUND)
            : $this->otp->resend($challenge->uuid, $purpose);
        if ($result->failure === OtpFailure::EXPIRED) {
            // Never resurrected: a fresh code (same ceilings) for the same destination.
            $result = $old
                ? $this->otp->issue(OtpPurpose::CONTACT_OLD_MOBILE, $context->person, $context->user, false, $request)
                : $this->otp->issueToProposedMobile(Person::query()->findOrFail($request->person_id), $context->user, $request);
        }

        if ($result->succeeded() || in_array($result->failure, [OtpFailure::DELIVERY_FAILED, OtpFailure::ALREADY_VERIFIED], true)) {
            return $request;
        }
        throw match ($result->failure) {
            OtpFailure::COOLDOWN => new FamilyAuthException(FamilyAuthError::OTP_COOLDOWN),
            OtpFailure::SEND_LIMIT => new FamilyAuthException(FamilyAuthError::OTP_SEND_LIMIT),
            OtpFailure::THROTTLED => new FamilyAuthException(FamilyAuthError::TOO_MANY_REQUESTS),
            default => $this->closed($request),
        };
    }

    private function closed(ChangeRequest $request): FamilyAuthException
    {
        $this->close->handle($request, ContactUpdateDenial::VERIFICATION_FAILED);

        return new FamilyAuthException(FamilyAuthError::CONTACT_UPDATE_FAILED);
    }
}
