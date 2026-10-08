<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestStatus;
use App\Enums\FamilyStatus;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\WorkflowEvent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared steps of the Change Request Domain Actions (PWA-5b). Lock order,
 * everywhere: the change_requests row FIRST, then the Family row, then the
 * target Person — the same Family → Person order as the existing actions
 * (CreateFamilyMembershipAction); Person-only actions never take a Family
 * lock after it, so no cycle exists. A submission locks only its Family.
 */
final class ChangeRequestWorkflow
{
    /** Re-read the request under its row lock: the only state transitions trust. */
    public static function lock(ChangeRequest $request): ChangeRequest
    {
        return ChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->first()
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
    }

    /**
     * Lock the canonical rows a request is about (Family, then the target
     * Person) and return its target, re-read. A Family that is no longer an
     * active registry Family cannot take a change (NOT_APPLICABLE).
     */
    public static function lockCanonical(ChangeRequest $locked): ChangeRequestTarget
    {
        $family = Family::withTrashed()->whereKey($locked->family_id)->lockForUpdate()->firstOrFail();
        if ($family->trashed() || $family->status !== FamilyStatus::ACTIVE) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }
        if ($locked->target_membership_id !== null) {
            $membership = FamilyMembership::query()->findOrFail($locked->target_membership_id);
            Person::withTrashed()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();
        }

        return ChangeRequestTarget::of($locked, $family);
    }

    /**
     * Re-validate a stored proposal against current canonical state (approve
     * and apply): the type's data rules, then its business preconditions. A
     * proposal that no longer validates is a failed precondition.
     */
    public static function verify(ChangeRequestHandler $handler, ChangeRequest $locked, ChangeRequestTarget $target): void
    {
        if ($locked->payload_version !== $handler->payloadVersion()) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }
        try {
            Validator::make($locked->submitted_data, $handler->dataRules($target->family))->validate();
        } catch (ValidationException) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
        $handler->preconditions($target, $locked->submitted_data);
    }

    /** The fresh base values still match the stored base fingerprint. */
    public static function assertBaseUnchanged(ChangeRequestHandler $handler, ChangeRequest $locked, ChangeRequestTarget $target): void
    {
        if (! ChangeRequestBase::matches($locked, $handler->baseValues($target))) {
            throw new ChangeRequestException(ChangeRequestException::BASE_CHANGED);
        }
    }

    public static function invalidTransition(): ChangeRequestException
    {
        return new ChangeRequestException(ChangeRequestException::INVALID_TRANSITION);
    }

    /**
     * A message as it will be stored (WorkflowEventRecorder::cleanText), or a
     * ValidationException on the given field: required and blank, or too long.
     */
    public static function message(?string $value, string $field, bool $required): ?string
    {
        try {
            $clean = WorkflowEventRecorder::cleanText($value, $field);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'النص أطول من '.WorkflowEventRecorder::MAX_MESSAGE_LENGTH.' حرف.']);
        }
        if ($clean === null && $required) {
            throw ValidationException::withMessages([$field => 'هذا الحقل مطلوب.']);
        }

        return $clean;
    }

    /**
     * Was the latest apply attempt REFUSED — the request can no longer be
     * applied as approved (AE-4)? Only then may an APPROVED request be
     * rejected, as NO_LONGER_APPLICABLE. $latestFailureCode is the latest
     * APPLY_FAILED reason code when the caller already selected it (lists);
     * otherwise it is read.
     */
    public static function applyWasRefused(ChangeRequest $request, ?string $latestFailureCode = null): bool
    {
        if ($request->last_apply_failed_at === null) {
            return false;
        }
        $code = $latestFailureCode ?? self::lastEvent($request, WorkflowEventType::APPLY_FAILED)?->reason_code;

        return $code !== null && ChangeRequestApplyFailure::tryFrom($code)?->isRefusal() === true;
    }

    /** The most recent event of a type on the request (for replay and AE-4 checks). */
    public static function lastEvent(ChangeRequest $request, WorkflowEventType $type): ?WorkflowEvent
    {
        return WorkflowEvent::query()
            ->where('workflowable_type', $request->getMorphClass())
            ->where('workflowable_id', $request->getKey())
            ->where('event_type', $type)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Is the request in $status BECAUSE this actor's $event put it there — the
     * latest status change (an APPLY_FAILED changes nothing) — so that a
     * repeated call is a replay, not a new transition?
     */
    public static function alreadyDoneBy(ChangeRequest $locked, ChangeRequestStatus $status, WorkflowEventType $event, int $actorUserId, ?string $publicMessage = null): bool
    {
        if ($locked->status !== $status) {
            return false;
        }
        $last = WorkflowEvent::query()
            ->where('workflowable_type', $locked->getMorphClass())
            ->where('workflowable_id', $locked->getKey())
            ->where('event_type', '!=', WorkflowEventType::APPLY_FAILED)
            ->orderByDesc('id')
            ->first();

        return $last !== null
            && $last->event_type === $event
            && (int) $last->actor_user_id === $actorUserId
            && $last->to_status === $status
            && ($publicMessage === null || $last->public_message === $publicMessage);
    }
}
