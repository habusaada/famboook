<?php

namespace App\Http\Resources;

use App\Support\ChangeRequests\ChangeRequestStaffActions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Change Request row of the Staff queue (PWA-5c, change-request.view).
 * Identity, family, requester and workflow milestones only — never the
 * proposal (submitted_data), the requester's reason, any message, the base
 * fingerprint, the client reference or an internal id.
 */
class ChangeRequestSummaryResource extends JsonResource
{
    /** Relations every response needs, for eager loading. */
    public const RELATIONS = [
        'family:id,family_code',
        'family.householdHeadMembership.person:id,full_name',
        'submitterPerson:id,full_name',
    ];

    public function toArray(Request $request): array
    {
        $typeAvailable = app(ChangeRequestTypes::class)->has($this->type);

        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => $typeAvailable,
            'status' => $this->status,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
            ],
            'submitted_by' => ['name' => $this->submitterPerson?->full_name],
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'apply_failure_count' => $this->apply_failure_count,
            // UX hint only; every action is re-authorized and re-checked.
            'available_actions' => ChangeRequestStaffActions::for($this->resource, $request->user(), $typeAvailable, $this->latest_apply_failure),
        ];
    }
}
