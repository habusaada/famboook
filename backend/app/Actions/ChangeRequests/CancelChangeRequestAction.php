<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\AuthOtpChallenge;
use App\Models\ChangeRequest;
use App\Models\User;
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
 *
 * CO-02.3b: only the submitting channel cancels (D7). The household cancels
 * FAMILY-channel requests only — never a Coordinator's request about its
 * Family. handleCoordinator(): the Coordinator who submitted a
 * COORDINATOR-channel request, while it is still in their current scope,
 * with coordinator-request.cancel; the same statuses.
 */
class CancelChangeRequestAction
{
    public function handle(FamilyAccessResult $context, ChangeRequest $request): ChangeRequestOutcome
    {
        $user = ChangeRequestActors::family($context, ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::CANCELLED));
        ChangeRequestActors::ownedBy($request, $context);
        ChangeRequestActors::familyChannel($request);

        return DB::transaction(function () use ($context, $request, $user) {
            $locked = ChangeRequestWorkflow::lock($request);
            ChangeRequestActors::ownedBy($locked, $context);
            ChangeRequestActors::familyChannel($locked);
            if ($locked->status === ChangeRequestStatus::CANCELLED && (int) $locked->cancelled_by === (int) $user->getKey()) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            if (ChangeRequestTransitions::find($from, ChangeRequestStatus::CANCELLED) === null) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $locked->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => $user->getKey(), 'cancelled_at' => now()])->save();
            WorkflowEventRecorder::record($locked, $from, WorkflowEventType::CANCELLED, WorkflowActorSide::FAMILY, $user->getKey());
            // FP-ADR-079: a cancelled contact update's codes stop working at once.
            if ($locked->type->isSystemVerified()) {
                AuthOtpChallenge::supersedeOpenForChangeRequest($locked->getKey());
            }

            return new ChangeRequestOutcome($locked);
        });
    }

    /** The submitting Coordinator withdraws their own request (CO-02.3b). */
    public function handleCoordinator(User $user, ChangeRequest $request): ChangeRequestOutcome
    {
        $coordinator = ChangeRequestActors::coordinator($user, ChangeRequestTransitions::assertCoordinatorAllowed(ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::CANCELLED));
        ChangeRequestActors::submittedByCoordinator($request, $coordinator);

        return DB::transaction(function () use ($coordinator, $request, $user) {
            $locked = ChangeRequestWorkflow::lock($request);
            ChangeRequestActors::submittedByCoordinator($locked, $coordinator);
            if ($locked->status === ChangeRequestStatus::CANCELLED && (int) $locked->cancelled_by === (int) $user->getKey()) {
                return new ChangeRequestOutcome($locked, replayed: true);
            }

            $from = $locked->status;
            if (ChangeRequestTransitions::findCoordinator($from, ChangeRequestStatus::CANCELLED) === null) {
                throw ChangeRequestWorkflow::invalidTransition();
            }

            $locked->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => $user->getKey(), 'cancelled_at' => now()])->save();
            WorkflowEventRecorder::record($locked, $from, WorkflowEventType::CANCELLED, WorkflowActorSide::COORDINATOR, $user->getKey());

            return new ChangeRequestOutcome($locked);
        });
    }
}
