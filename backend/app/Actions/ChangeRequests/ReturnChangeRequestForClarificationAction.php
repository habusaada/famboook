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
 * Staff ask the family for more information (docs/05 §59): UNDER_REVIEW →
 * RETURNED_FOR_CLARIFICATION. The family-visible message is required; the
 * Staff-only internal note is optional and never copied into it (docs/06
 * §71–§72). The proposal does not change; no FamilyActivity.
 */
class ReturnChangeRequestForClarificationAction
{
    public function handle(ChangeRequest $request, User $staff, ?string $message, ?string $internalNote = null): ChangeRequestOutcome
    {
        $transition = ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::RETURNED_FOR_CLARIFICATION);
        ChangeRequestActors::staff($staff, $transition);
        $message = ChangeRequestWorkflow::message($message, 'message', true);
        $internalNote = ChangeRequestWorkflow::message($internalNote, 'internal_note', false);

        return DB::transaction(function () use ($request, $staff, $message, $internalNote) {
            $locked = ChangeRequestWorkflow::lock($request);
            if (ChangeRequestWorkflow::alreadyDoneBy($locked, ChangeRequestStatus::RETURNED_FOR_CLARIFICATION, WorkflowEventType::RETURNED, $staff->getKey(), $message)) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }
            if ($locked->status !== ChangeRequestStatus::UNDER_REVIEW) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $locked->forceFill(['status' => ChangeRequestStatus::RETURNED_FOR_CLARIFICATION])->save();
            WorkflowEventRecorder::record($locked, ChangeRequestStatus::UNDER_REVIEW, WorkflowEventType::RETURNED, WorkflowActorSide::STAFF, $staff->getKey(),
                publicMessage: $message, internalNote: $internalNote);

            return new ChangeRequestOutcome($locked);
        });
    }
}
