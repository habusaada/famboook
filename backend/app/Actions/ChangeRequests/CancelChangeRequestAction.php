<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Support\Facades\DB;

/**
 * The family withdraws its request (AE-3) — terminal CANCELLED, allowed from
 * SUBMITTED, UNDER_REVIEW, RETURNED_FOR_CLARIFICATION and RESUBMITTED; never
 * once APPROVED or finished. Any eligible head of the request's Family may
 * cancel (Family-subject, AE-9). No canonical change, no FamilyActivity.
 */
class CancelChangeRequestAction
{
    public function handle(FamilyAccessResult $context, ChangeRequest $request): ChangeRequestOutcome
    {
        $user = ChangeRequestActors::family($context, ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::CANCELLED));
        ChangeRequestActors::ownedBy($request, $context);

        return DB::transaction(function () use ($context, $request, $user) {
            $locked = ChangeRequestWorkflow::lock($request);
            ChangeRequestActors::ownedBy($locked, $context);
            if ($locked->status === ChangeRequestStatus::CANCELLED && (int) $locked->cancelled_by === (int) $user->getKey()) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            if (ChangeRequestTransitions::find($from, ChangeRequestStatus::CANCELLED) === null) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $locked->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => $user->getKey(), 'cancelled_at' => now()])->save();
            WorkflowEventRecorder::record($locked, $from, WorkflowEventType::CANCELLED, WorkflowActorSide::FAMILY, $user->getKey());

            return new ChangeRequestOutcome($locked);
        });
    }
}
