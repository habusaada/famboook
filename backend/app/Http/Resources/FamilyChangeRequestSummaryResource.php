<?php

namespace App\Http\Resources;

use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\FamilyChangeRequestActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the Family's Change Request history (PWA-5e). Family-subject: the
 * whole Family's history, whoever submitted it. Identity, type, status and
 * milestone dates only — never the proposal, a message, a Staff name, the
 * base fingerprint, the client reference, apply diagnostics or an internal id.
 */
class FamilyChangeRequestSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => app(ChangeRequestTypes::class)->has($this->type),
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'available_actions' => FamilyChangeRequestActions::for($this->resource, $request->user()),
        ];
    }
}
