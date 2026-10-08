<?php

namespace App\Http\Resources;

use App\Enums\WorkflowEventType;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\FamilyChangeRequestActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Family's Change Requests as the FAMILY sees it (PWA-5e) — a
 * field-reviewed view, separate from the Staff ChangeRequestResource.
 *
 * - The proposal only through the type handler's FAMILY presentation
 *   (`{rows: [{label, current, proposed}]}`, the approved V1 shape); without
 *   a registered handler `type_available` is false and `presentation` null —
 *   submitted_data is never serialized raw.
 * - The timeline holds the family-visible events only: APPLY_FAILED
 *   diagnostics are left out, and each event is a FamilyWorkflowEventResource
 *   (no internal note, no actor name, no metadata).
 * - The rejection: its reason code and the family-visible message only.
 * - Never: Staff names, the base fingerprint or key version, the client
 *   reference, apply-failure counts or codes, internal ids.
 */
class FamilyChangeRequestResource extends JsonResource
{
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
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reason' => $this->reason,
            'presentation' => $typeAvailable
                ? $types->handler($this->type)->present($this->resource, ChangeRequestPresentationContext::family())
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
            'available_actions' => FamilyChangeRequestActions::for($this->resource, $request->user()),
        ];
    }
}
