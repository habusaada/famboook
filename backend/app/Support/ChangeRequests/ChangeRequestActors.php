<?php

namespace App\Support\ChangeRequests;

use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * Defence in depth for the Change Request Domain Actions (PWA-5b). Routes
 * (PWA-5c / 5e) will add their own boundaries, but invoking an Action
 * directly must not cross the family / Staff line either:
 *
 * - a family transition needs the trusted family.context result — an
 *   eligible household head with a resolved Family — on a family-side
 *   account holding the transition's permission; the request must belong
 *   to THAT Family (Family-subject ownership, AE-9);
 * - a Staff transition needs a Staff-side account holding the transition's
 *   permission. A family-side account (FAMILY_USER, COORDINATOR) never
 *   passes, whatever it holds;
 * - a COORDINATOR transition (CO-02.3b) needs a Coordinator right now
 *   (CoordinatorScopes::context, re-resolved on every call) holding the
 *   transition's coordinator-request.* permission; the request must be
 *   THIS user's COORDINATOR-channel request still in the CURRENT scope.
 *   change-request.* never authorizes the coordinator channel;
 * - the household acts only on FAMILY-channel requests: it sees a
 *   Coordinator's request about its Family but never answers or cancels it.
 *
 * Every refusal is CHANGE_REQUEST_ACTOR_NOT_ALLOWED (no reason disclosed).
 */
final class ChangeRequestActors
{
    /** The family-side account acting through its resolved Family context. */
    public static function family(FamilyAccessResult $context, ChangeRequestTransition $transition): User
    {
        $user = $context->user;
        if ($transition->actorSide !== WorkflowActorSide::FAMILY
            || ! $context->hasFamilyContext()
            || $user === null
            || ! AccountSide::isFamily($user)
            || ! $user->can($transition->permission)) {
            throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        }

        return $user;
    }

    /** The request belongs to the context's Family; otherwise it does not exist for it. */
    public static function ownedBy(ChangeRequest $request, FamilyAccessResult $context): void
    {
        if ((int) $request->family_id !== (int) $context->family?->getKey()) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
    }

    /**
     * Cancel and resubmit belong to the submitting channel (CO-02.3b, D7):
     * the household never acts on a Coordinator's request about its Family.
     */
    public static function familyChannel(ChangeRequest $request): void
    {
        if ($request->submission_channel !== SubmissionChannel::FAMILY) {
            throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
    }

    /**
     * The Coordinator acting now: a COORDINATOR transition, the transition's
     * coordinator-request.* permission and a granted Coordinator context
     * (own eligible household, role, coordinator-space.access, at least one
     * effective assignment) — resolved here, never handed in.
     */
    public static function coordinator(User $user, ChangeRequestTransition $transition): CoordinatorAccessResult
    {
        if ($transition->actorSide !== WorkflowActorSide::COORDINATOR
            || ! $user->is_active
            || ! AccountSide::isFamily($user)
            || ! $user->can($transition->permission)) {
            throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
        $context = app(CoordinatorScopes::class)->context($user);
        if (! $context->allowed()) {
            throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        }

        return $context;
    }

    /**
     * The request is this Coordinator's own COORDINATOR-channel request and
     * still in the current scope; otherwise it does not exist for them (the
     * same answer as a nonexistent request).
     */
    public static function submittedByCoordinator(ChangeRequest $request, CoordinatorAccessResult $context): void
    {
        $visible = $request->submission_channel === SubmissionChannel::COORDINATOR
            && (int) $request->submitted_by === (int) $context->user?->getKey()
            && app(CoordinatorScopes::class)->requests($context)->whereKey($request->getKey())->exists();
        if (! $visible) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
    }

    public static function staff(User $user, ChangeRequestTransition $transition): void
    {
        if ($transition->actorSide !== WorkflowActorSide::STAFF
            || ! $user->is_active
            || ! AccountSide::isStaff($user)
            || ! $user->can($transition->permission)) {
            throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
    }
}
