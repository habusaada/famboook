<?php

namespace App\Http\Resources;

use App\Enums\ChangeRequestAttestation;
use App\Enums\WorkflowEventType;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestStaffActions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\RequiresApprovalAttestation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Staff review view of one Change Request (PWA-5c, change-request.view).
 *
 * The proposal is shown ONLY through its type handler's STAFF presentation
 * (current vs proposed, shaped and masked by the handler) — submitted_data
 * is never serialized raw. A request whose type has no registered handler
 * (the Production registry is empty until PWA-6) keeps its identity,
 * workflow state and timeline, with type_available = false and
 * presentation = null. Never: the base fingerprint or key version, the
 * client reference, an internal id.
 */
class ChangeRequestResource extends JsonResource
{
    /** Relations the detail needs, for eager loading. */
    public const RELATIONS = [
        ...ChangeRequestSummaryResource::RELATIONS,
        'person:id,person_code,full_name',
        'reviewer:id,name',
        'approver:id,name',
        'rejecter:id,name',
        'applier:id,name',
        'workflowEvents.actor:id,name',
    ];

    public function toArray(Request $request): array
    {
        $types = app(ChangeRequestTypes::class);
        $typeAvailable = $types->has($this->type);
        $events = $this->workflowEvents;
        $lastFailure = $events->last(fn ($event) => $event->event_type === WorkflowEventType::APPLY_FAILED);

        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => $typeAvailable,
            'payload_version' => $this->payload_version,
            'status' => $this->status,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
            ],
            'target_person' => $this->person ? [
                'person_code' => $this->person->person_code,
                'full_name' => $this->person->full_name,
            ] : null,
            'submitted_by' => ['name' => $this->submitterPerson?->full_name],
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reason' => $this->reason,
            'presentation' => $typeAvailable
                ? $types->handler($this->type)->present($this->resource, ChangeRequestPresentationContext::staff($request->user()))
                : null,
            'review' => [
                'reviewed_by' => $this->reviewer ? ['name' => $this->reviewer->name] : null,
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                'approved_by' => $this->approver ? ['name' => $this->approver->name] : null,
                'approved_at' => $this->approved_at?->toIso8601String(),
                'applied_by' => $this->applier ? ['name' => $this->applier->name] : null,
                'applied_at' => $this->applied_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'rejection' => $this->rejected_at === null ? null : [
                'reason_code' => $this->rejection_reason_code,
                'message' => $this->rejection_reason,
                'rejected_by' => $this->rejecter ? ['name' => $this->rejecter->name] : null,
                'rejected_at' => $this->rejected_at->toIso8601String(),
            ],
            'apply_failures' => [
                'count' => $this->apply_failure_count,
                'last_failed_at' => $this->last_apply_failed_at?->toIso8601String(),
                'last_code' => $lastFailure?->reason_code,
            ],
            'timeline' => WorkflowEventResource::collection($events),
            // FP-ADR-076: what approving this type requires from the reviewer
            // (codes only); empty for types without attestations.
            'approval_attestations' => $typeAvailable && ($handler = $types->handler($this->type)) instanceof RequiresApprovalAttestation
                ? array_map(fn (ChangeRequestAttestation $a) => $a->value, $handler->requiredAttestations())
                : [],
            // UX hint only; every action is re-authorized and re-checked.
            'available_actions' => ChangeRequestStaffActions::for($this->resource, $request->user(), $typeAvailable, $lastFailure?->reason_code),
        ];
    }
}
