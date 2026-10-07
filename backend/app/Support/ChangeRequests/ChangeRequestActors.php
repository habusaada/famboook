<?php

namespace App\Support\ChangeRequests;

use App\Enums\WorkflowActorSide;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\AccountSide;
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
 *   passes, whatever it holds.
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
