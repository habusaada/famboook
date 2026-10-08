<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Staff reject a request (docs/05 §61, docs/03 §76) — terminal. A
 * controlled reason code is required; the family-visible message is
 * optional except for OTHER (which says nothing on its own); the Staff-only
 * note is optional and stays on the event. Records CHANGE_REQUEST_REJECTED.
 *
 * Normally from UNDER_REVIEW. From APPROVED only as NO_LONGER_APPLICABLE
 * and only after a recorded REFUSED apply (AE-4): the latest APPLY_FAILED
 * event must carry a refusal code (base changed, precondition failed, not
 * applicable) — an unexpected system failure is retried, not rejected. It is
 * never a generic "undo approval".
 */
class RejectChangeRequestAction
{
    public function handle(
        ChangeRequest $request,
        User $staff,
        ChangeRequestRejectionReason $reason,
        ?string $message = null,
        ?string $internalNote = null,
    ): ChangeRequestOutcome {
        ChangeRequestActors::staff($staff, ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::REJECTED, $reason));
        $message = ChangeRequestWorkflow::message($message, 'message', $reason === ChangeRequestRejectionReason::OTHER);
        $internalNote = ChangeRequestWorkflow::message($internalNote, 'internal_note', false);

        return DB::transaction(function () use ($request, $staff, $reason, $message, $internalNote) {
            $locked = ChangeRequestWorkflow::lock($request);
            if (ChangeRequestWorkflow::alreadyDoneBy($locked, ChangeRequestStatus::REJECTED, WorkflowEventType::REJECTED, $staff->getKey())
                && $locked->rejection_reason_code === $reason) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            $transition = ChangeRequestTransitions::find($from, ChangeRequestStatus::REJECTED);
            if ($transition === null || ! $transition->accepts($reason)) {
                throw ChangeRequestWorkflow::invalidTransition();
            }
            ChangeRequestActors::staff($staff, $transition);
            if ($from === ChangeRequestStatus::APPROVED && ! ChangeRequestWorkflow::applyWasRefused($locked)) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $locked->forceFill([
                'status' => ChangeRequestStatus::REJECTED,
                'rejected_by' => $staff->getKey(),
                'rejected_at' => now(),
                'rejection_reason_code' => $reason,
                'rejection_reason' => $message,
            ])->save();
            WorkflowEventRecorder::record($locked, $from, WorkflowEventType::REJECTED, WorkflowActorSide::STAFF, $staff->getKey(),
                reason: $reason, publicMessage: $message, internalNote: $internalNote);
            FamilyActivityLog::record($locked->family_id, FamilyActivityType::CHANGE_REQUEST_REJECTED, $locked, $staff->getKey(), [
                'request_type' => $locked->type->value,
            ]);

            return new ChangeRequestOutcome($locked);
        });
    }
}
