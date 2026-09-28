<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cross-family assessment registry. Identity and lifecycle
 * only: the family's code, household-head name and branch — never notes,
 * ratings detail, National IDs, contact or health data. The assessed domain
 * count is derived (withCount), not stored.
 */
class AssessmentRegistryResource extends JsonResource
{
    /** Eager loads required by toArray() (no N+1). */
    public const RELATIONS = [
        'family:id,family_code,branch_id',
        'family.branch:id,name',
        'family.householdHeadMembership.person:id,full_name',
        'creator:id,name',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'assessment_date' => $this->assessment_date->toDateString(),
            'status' => $this->status,
            'assessed_domain_count' => $this->results_count,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
                'branch_name' => $this->family->branch?->name,
            ],
            'created_by' => $this->creator ? ['name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
