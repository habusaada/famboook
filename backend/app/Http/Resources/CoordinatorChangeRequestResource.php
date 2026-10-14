<?php

namespace App\Http\Resources;

use App\Enums\WorkflowEventType;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\CoordinatorChangeRequestActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Coordinator's own requests as the Coordinator sees it
 * (CO-02.4) — a field-reviewed view, separate from the Staff and Family
 * resources.
 *
 * - The proposal only through the type handler's COORDINATOR presentation
 *   (identifiers masked; never the Staff-only registry match, another
 *   Person's name or whether a National ID exists).
 * - The timeline: the family-visible events (FamilyWorkflowEventResource —
 *   no internal note, no actor name or id, no metadata); APPLY_FAILED
 *   diagnostics are left out.
 * - The rejection: its reason code and the family-visible message only.
 * - Never: Staff names, the base fingerprint or key version, the client
 *   reference, apply-failure counts or codes, internal ids, and nothing of
 *   the Family beyond its public code.
 */
class CoordinatorChangeRequestResource extends JsonResource
{
    /** Relations the detail needs, for eager loading. */
    public const RELATIONS = [...CoordinatorChangeRequestSummaryResource::RELATIONS, 'workflowEvents'];

    public function toArray(Request $request): array
    {
        $types = app(ChangeRequestTypes::class);
        $typeAvailable = $types->has($this->type);
        $events = $this->workflowEvents->reject(fn ($event) => $event->event_type === WorkflowEventType::APPLY_FAILED)->values();

        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => $typeAvailable,
            'status' => $this->status,
            'family_code' => $this->family?->family_code ?? $this->createdFamily?->family_code,
            'placement' => ChangeRequestSummaryResource::placement($this->resource),
            'created_family_code' => $this->createdFamily?->family_code,
            'summary' => CoordinatorChangeRequestSummaryResource::summary($this->resource),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reason' => $this->reason,
            'presentation' => $typeAvailable
                ? $types->handler($this->type)->present($this->resource, ChangeRequestPresentationContext::coordinator())
                : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rejection' => $this->rejected_at === null ? null : [
                'reason_code' => $this->rejection_reason_code,
                'message' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at->toIso8601String(),
            ],
            'timeline' => FamilyWorkflowEventResource::collection($events),
            // UX hint only; every action is re-authorized and re-checked.
            'available_actions' => CoordinatorChangeRequestActions::for($this->resource, $request->user()),
        ];
    }
}
