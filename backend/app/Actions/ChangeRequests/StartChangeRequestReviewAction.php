<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Staff open a request for review (docs/05 §58): SUBMITTED or RESUBMITTED →
 * UNDER_REVIEW, recording who and when (reviewed_by / reviewed_at — the
 * latest opening). Nothing of the proposal changes; no FamilyActivity.
 * Repeating it by the same reviewer is a replay; anyone else gets
 * CHANGE_REQUEST_INVALID_TRANSITION.
 */
class StartChangeRequestReviewAction
{
    public function handle(ChangeRequest $request, User $staff): ChangeRequestOutcome
    {
        ChangeRequestActors::staff($staff, ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::UNDER_REVIEW));

        return DB::transaction(function () use ($request, $staff) {
            $locked = ChangeRequestWorkflow::lock($request);
            if (ChangeRequestWorkflow::alreadyDoneBy($locked, ChangeRequestStatus::UNDER_REVIEW, WorkflowEventType::REVIEW_STARTED, $staff->getKey())) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            $transition = ChangeRequestTransitions::find($from, ChangeRequestStatus::UNDER_REVIEW) ?? throw ChangeRequestWorkflow::invalidTransition();
            ChangeRequestActors::staff($staff, $transition);

            $locked->forceFill(['status' => ChangeRequestStatus::UNDER_REVIEW, 'reviewed_by' => $staff->getKey(), 'reviewed_at' => now()])->save();
            WorkflowEventRecorder::record($locked, $from, WorkflowEventType::REVIEW_STARTED, WorkflowActorSide::STAFF, $staff->getKey());

            return new ChangeRequestOutcome($locked);
        });
    }
}
