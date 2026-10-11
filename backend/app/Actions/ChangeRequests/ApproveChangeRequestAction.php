<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\RequiresApprovalAttestation;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function handle(ChangeRequest $request, User $staff, ?ChangeRequestApprovalEvidence $evidence = null): ChangeRequestOutcome
    {
        $evidence ??= ChangeRequestApprovalEvidence::none();
        $transition = ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::APPROVED);
        ChangeRequestActors::staff($staff, $transition);

        return DB::transaction(function () use ($request, $staff, $evidence) {
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
            $attested = self::attestations($handler, $locked, $target, $evidence);

            $locked->forceFill(['status' => ChangeRequestStatus::APPROVED, 'approved_by' => $staff->getKey(), 'approved_at' => now()])->save();
            WorkflowEventRecorder::record($locked, ChangeRequestStatus::UNDER_REVIEW, WorkflowEventType::APPROVED, WorkflowActorSide::STAFF, $staff->getKey(),
                metadata: $attested);

            return new ChangeRequestOutcome($locked);
        });
    }

    /**
     * FP-ADR-076: a type that requires attestations is approved only with
     * every one of them and once its approval-only checks pass; the codes
     * (never the evidence) go into the APPROVED event. Any other type takes
     * no evidence at all.
     *
     * @return array<string, string> the event metadata
     */
    private static function attestations(ChangeRequestHandler $handler, ChangeRequest $locked, ChangeRequestTarget $target, ChangeRequestApprovalEvidence $evidence): array
    {
        if (! $handler instanceof RequiresApprovalAttestation) {
            if ($evidence->attestations !== [] || $evidence->verifiedNationalId !== null) {
                throw ValidationException::withMessages(['attestations' => 'لا يتطلب هذا النوع من الطلبات إقرارات عند الاعتماد.']);
            }

            return [];
        }

        $metadata = [];
        foreach ($handler->requiredAttestations() as $attestation) {
            if (! $evidence->attests($attestation)) {
                throw ValidationException::withMessages(['attestations' => 'يجب تأكيد كل الإقرارات المطلوبة قبل اعتماد الطلب.']);
            }
            $metadata[$attestation->metadataKey()] = $attestation->value;
        }
        $handler->assertApprovable($locked, $target, $evidence);

        return $metadata;
    }
}
