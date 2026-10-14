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
        // CO-02.3b: a proposed Family (no family_id) shows its placement and,
        // once APPLIED, the Family it created.
        'proposedClan:id,code,name',
        'proposedBranch:id,code,name',
        'createdFamily:id,family_code',
    ];

    /**
     * The Family a request is about; NULL for a proposed Family (CO-02.3b),
     * which is described by `placement` and, once APPLIED,
     * `created_family_code`.
     *
     * @return array{family_code: string, household_head_name: string|null}|null
     */
    public static function familySummary(mixed $request): ?array
    {
        if ($request->family !== null) {
            return [
                'family_code' => $request->family->family_code,
                'household_head_name' => $request->family->householdHeadMembership?->person?->full_name,
            ];
        }

        return null;
    }

    /**
     * The proposed placement of a request for a NEW Family (CO-02.3b);
     * NULL for every other request.
     *
     * @return array{clan: array{code: string, name: string|null}, branch: array{code: string, name: string|null}|null}|null
     */
    public static function placement(mixed $request): ?array
    {
        if ($request->proposedClan === null) {
            return null;
        }

        return [
            'clan' => ['code' => $request->proposedClan->code, 'name' => $request->proposedClan->name],
            'branch' => $request->proposedBranch ? ['code' => $request->proposedBranch->code, 'name' => $request->proposedBranch->name] : null,
        ];
    }

    public function toArray(Request $request): array
    {
        $typeAvailable = app(ChangeRequestTypes::class)->has($this->type);

        return [
            'id' => $this->uuid,
            'request_code' => $this->request_code,
            'type' => $this->type,
            'type_available' => $typeAvailable,
            'status' => $this->status,
            'submission_channel' => $this->submission_channel,
            'family' => self::familySummary($this->resource),
            'placement' => self::placement($this->resource),
            'created_family_code' => $this->createdFamily?->family_code,
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
