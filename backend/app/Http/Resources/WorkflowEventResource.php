<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Change Request workflow event for Staff (PWA-5c). internal_note is
 * present ONLY for holders of change-request.view-internal-notes — the key
 * is absent otherwise — and is never folded into public_message. Event
 * metadata is not exposed (no key is allow-listed for display). Actors are
 * shown by display name only: no ids, no emails.
 */
class WorkflowEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event_type' => $this->event_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->actor ? ['name' => $this->actor->name] : null,
            'actor_side' => $this->actor_side,
            'reason_code' => $this->reason_code,
            'public_message' => $this->public_message,
            'internal_note' => $this->when(
                $request->user()?->can('change-request.view-internal-notes') === true,
                fn () => $this->internal_note,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
