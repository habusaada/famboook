<?php

namespace App\Http\Resources;

use App\Enums\ChangeRequestType;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\CoordinatorChangeRequestActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the Coordinator's own requests (CO-02.4). Identity, type, status,
 * the Family by its public code (or, for a proposed Family, its placement
 * and, once APPLIED, the created Family's code), the proposed Person's name
 * (CO-02.5) and milestone dates only — never the rest of the proposal, a
 * message, a Staff name,
 * the base fingerprint, the client reference, apply diagnostics or an
 * internal id.
 */
class CoordinatorChangeRequestSummaryResource extends JsonResource
{
    /**
     * A safe one-line summary: the name of the Person the Coordinator
     * proposed (the member, or a new Family's head) — what they typed
     * themselves; never an identifier or a registry value.
     */
    public static function summary(mixed $request): ?string
    {
        $data = $request->submitted_data;

        return match ($request->type) {
            ChangeRequestType::ADD_FAMILY_MEMBER => $data['full_name'] ?? null,
            ChangeRequestType::REGISTER_FAMILY => $data['household_head']['full_name'] ?? null,
            default => null,
        };
    }

    /** Relations every response needs, for eager loading. */
    public const RELATIONS = [
        'family:id,family_code',
        'createdFamily:id,family_code',
        'proposedClan:id,code,name',
        'proposedBranch:id,code,name',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => app(ChangeRequestTypes::class)->has($this->type),
            'status' => $this->status,
            'family_code' => $this->family?->family_code ?? $this->createdFamily?->family_code,
            'placement' => ChangeRequestSummaryResource::placement($this->resource),
            // CO-02.5: the Family a proposal created, once APPLIED.
            'created_family_code' => $this->createdFamily?->family_code,
            'summary' => self::summary($this->resource),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            // UX hint only; every action is re-authorized and re-checked.
            'available_actions' => CoordinatorChangeRequestActions::for($this->resource, $request->user()),
        ];
    }
}
