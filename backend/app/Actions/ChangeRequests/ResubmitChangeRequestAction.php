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
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Support\Facades\DB;

/**
 * The family answers a clarification request (docs/05 §60, AE-5):
 * RETURNED_FOR_CLARIFICATION → RESUBMITTED with a required text response,
 * stored as the RESUBMITTED event's family-visible message. The proposal —
 * submitted_data, target, base fingerprint — never changes; wrong data means
 * rejection and a new request. Any eligible head of the request's Family may
 * answer (Family-subject, AE-9). No FamilyActivity.
 *
 * CO-02.3b: only the submitting channel answers (D7). The household answers
 * FAMILY-channel requests only; handleCoordinator() is the submitting
 * Coordinator, while the request is still in their current scope, with
 * coordinator-request.resubmit.
 */
class ResubmitChangeRequestAction
{
    public function handle(FamilyAccessResult $context, ChangeRequest $request, ?string $response): ChangeRequestOutcome
    {
        $transition = ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::RETURNED_FOR_CLARIFICATION, ChangeRequestStatus::RESUBMITTED);
        $user = ChangeRequestActors::family($context, $transition);
        ChangeRequestActors::ownedBy($request, $context);
        ChangeRequestActors::familyChannel($request);
        $response = ChangeRequestWorkflow::message($response, 'response', true);

        return DB::transaction(function () use ($context, $request, $user, $response) {
            $locked = ChangeRequestWorkflow::lock($request);
            ChangeRequestActors::ownedBy($locked, $context);
            ChangeRequestActors::familyChannel($locked);

            return $this->resubmit($locked, $user, $response, WorkflowActorSide::FAMILY);
        });
    }

    /** The submitting Coordinator answers the clarification of their own request (CO-02.3b). */
    public function handleCoordinator(User $user, ChangeRequest $request, ?string $response): ChangeRequestOutcome
    {
        $transition = ChangeRequestTransitions::assertCoordinatorAllowed(ChangeRequestStatus::RETURNED_FOR_CLARIFICATION, ChangeRequestStatus::RESUBMITTED);
        $coordinator = ChangeRequestActors::coordinator($user, $transition);
        ChangeRequestActors::submittedByCoordinator($request, $coordinator);
        $response = ChangeRequestWorkflow::message($response, 'response', true);

        return DB::transaction(function () use ($coordinator, $request, $user, $response) {
            $locked = ChangeRequestWorkflow::lock($request);
            ChangeRequestActors::submittedByCoordinator($locked, $coordinator);

            return $this->resubmit($locked, $user, $response, WorkflowActorSide::COORDINATOR);
        });
    }

    private function resubmit(ChangeRequest $locked, User $user, string $response, WorkflowActorSide $side): ChangeRequestOutcome
    {
        if (ChangeRequestWorkflow::alreadyDoneBy($locked, ChangeRequestStatus::RESUBMITTED, WorkflowEventType::RESUBMITTED, $user->getKey(), $response)) {
            return new ChangeRequestOutcome($locked, replayed: true);
        }
        if ($locked->status !== ChangeRequestStatus::RETURNED_FOR_CLARIFICATION) {
            throw ChangeRequestWorkflow::invalidTransition();
        }

        $locked->forceFill(['status' => ChangeRequestStatus::RESUBMITTED])->save();
        WorkflowEventRecorder::record($locked, ChangeRequestStatus::RETURNED_FOR_CLARIFICATION, WorkflowEventType::RESUBMITTED,
            $side, $user->getKey(), publicMessage: $response);

        return new ChangeRequestOutcome($locked);
    }
}
