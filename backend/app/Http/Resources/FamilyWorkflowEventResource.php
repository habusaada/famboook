<?php

namespace App\Http\Resources;

use App\Enums\WorkflowEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One workflow event as the FAMILY sees it (PWA-5e) — a field-reviewed
 * subset, separate from the Staff WorkflowEventResource: the event, its
 * status change, the acting side, the family-visible message and, for a
 * rejection only, the reason code. Never the Staff-only internal note, an
 * actor's name or id, metadata, or apply-failure diagnostics (APPLY_FAILED
 * events are not part of the family timeline at all — see the controller).
 */
class FamilyWorkflowEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event_type' => $this->event_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_side' => $this->actor_side,
            'reason_code' => $this->event_type === WorkflowEventType::REJECTED ? $this->reason_code : null,
            'public_message' => $this->public_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
