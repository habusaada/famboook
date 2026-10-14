<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\SubmissionChannel;
use App\Models\ChangeRequest;
use App\Models\User;

/**
 * The family-side actions a household head may OFFER on one of the
 * Family's requests (PWA-5e) — derived from the authoritative transition
 * table and the user's permissions; never authorization. The Domain Action
 * re-checks everything under the request lock, so a concurrent Staff
 * decision can still refuse it.
 */
final class FamilyChangeRequestActions
{
    /** @return list<string> */
    public static function for(ChangeRequest $request, User $user): array
    {
        // CO-02.3b (D7): a Coordinator's request about the Family is shown, never answered or cancelled here.
        if ($request->submission_channel !== SubmissionChannel::FAMILY) {
            return [];
        }
        $actions = [];
        foreach (['resubmit' => ChangeRequestStatus::RESUBMITTED, 'cancel' => ChangeRequestStatus::CANCELLED] as $action => $to) {
            $transition = ChangeRequestTransitions::find($request->status, $to);
            if ($transition !== null && $user->can($transition->permission)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }
}
