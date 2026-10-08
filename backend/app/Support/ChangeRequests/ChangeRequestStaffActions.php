<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestStatus as S;
use App\Models\ChangeRequest;
use App\Models\User;

/**
 * The workflow actions a Staff user may OFFER on a request (PWA-5c) — a UX
 * hint from the request's status, the user's permissions, whether its type
 * has a registered handler and (for rejecting an approved request) whether
 * its latest apply attempt was refused. Never authorization: every call is
 * re-checked by its route and by the Domain Action under the request lock,
 * and fresh canonical preconditions may still refuse it.
 */
final class ChangeRequestStaffActions
{
    /** @return list<string> */
    public static function for(ChangeRequest $request, User $user, bool $typeAvailable, ?string $latestApplyFailure = null): array
    {
        $status = $request->status;
        $actions = [];

        if (in_array($status, [S::SUBMITTED, S::RESUBMITTED], true) && $user->can('change-request.review')) {
            $actions[] = 'start_review';
        }
        if ($status === S::UNDER_REVIEW && $user->can('change-request.return')) {
            $actions[] = 'return';
        }
        if ($status === S::UNDER_REVIEW && $typeAvailable && $user->can('change-request.approve')) {
            $actions[] = 'approve';
        }
        if ($user->can('change-request.reject') && ($status === S::UNDER_REVIEW
            || ($status === S::APPROVED && ChangeRequestWorkflow::applyWasRefused($request, $latestApplyFailure)))) {
            $actions[] = 'reject';
        }
        if ($status === S::APPROVED && $typeAvailable && $user->can('change-request.apply')) {
            $actions[] = 'apply';
        }

        return $actions;
    }
}
