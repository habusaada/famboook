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
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Staff approve a request (docs/05 §62): UNDER_REVIEW → APPROVED. Approval
 * authorizes the operation; it changes NO canonical data (APPROVED ≠
 * APPLIED) and records no FamilyActivity.
 *
 * In one transaction, in the lock order request → Family → Person: the
 * proposal is re-validated against current canonical state (data rules,
 * preconditions) and the base fingerprint recomputed — a request whose base
 * data changed is refused (CHANGE_REQUEST_BASE_CHANGED), never approved over
 * newer data.
 */
class ApproveChangeRequestAction
{
    public function __construct(private readonly ChangeRequestTypes $types) {}

    public function handle(ChangeRequest $request, User $staff): ChangeRequestOutcome
    {
        $transition = ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::APPROVED);
        ChangeRequestActors::staff($staff, $transition);

        return DB::transaction(function () use ($request, $staff) {
            $locked = ChangeRequestWorkflow::lock($request);
            if (ChangeRequestWorkflow::alreadyDoneBy($locked, ChangeRequestStatus::APPROVED, WorkflowEventType::APPROVED, $staff->getKey())) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }
            if ($locked->status !== ChangeRequestStatus::UNDER_REVIEW) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $handler = $this->types->handler($locked->type);
            $target = ChangeRequestWorkflow::lockCanonical($locked);
            ChangeRequestWorkflow::verify($handler, $locked, $target);
            ChangeRequestWorkflow::assertBaseUnchanged($handler, $locked, $target);

            $locked->forceFill(['status' => ChangeRequestStatus::APPROVED, 'approved_by' => $staff->getKey(), 'approved_at' => now()])->save();
            WorkflowEventRecorder::record($locked, ChangeRequestStatus::UNDER_REVIEW, WorkflowEventType::APPROVED, WorkflowActorSide::STAFF, $staff->getKey());

            return new ChangeRequestOutcome($locked);
        });
    }
}
