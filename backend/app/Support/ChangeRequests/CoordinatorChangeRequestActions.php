<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\SubmissionChannel;
use App\Models\ChangeRequest;
use App\Models\User;

/**
 * The actions a Coordinator may OFFER on one of their own requests (CO-02.4)
 * — derived from the coordinator transition table and the user's
 * coordinator-request.* permissions; never authorization. The Domain Action
 * re-checks actor, ownership, scope and status under the request lock.
 */
final class CoordinatorChangeRequestActions
{
    /** @return list<string> */
    public static function for(ChangeRequest $request, User $user): array
    {
        if ($request->submission_channel !== SubmissionChannel::COORDINATOR || (int) $request->submitted_by !== (int) $user->getKey()) {
            return [];
        }
        $actions = [];
        foreach (['resubmit' => ChangeRequestStatus::RESUBMITTED, 'cancel' => ChangeRequestStatus::CANCELLED] as $action => $to) {
            $transition = ChangeRequestTransitions::findCoordinator($request->status, $to);
            if ($transition !== null && $user->can($transition->permission)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }
}
