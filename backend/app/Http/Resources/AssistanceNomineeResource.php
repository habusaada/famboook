<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nominee row. Safe identity only: family code and household-head name,
 * the person's code and name, and for NEED nominations the need's title,
 * category, priority and status — never its description. No National ID,
 * phone numbers, health data, internal ids or emails. The targeting
 * criteria snapshot is not exposed here.
 */
class AssistanceNomineeResource extends JsonResource
{
    public const RELATIONS = [
        'family.householdHeadMembership.person',
        'person',
        'sourceNeed.category',
        'nominator:id,name',
        'remover:id,name',
    ];

    public function toArray(Request $request): array
    {
        $need = $this->sourceNeed;

        return [
            'id' => $this->uuid,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
            ],
            // null = family-level nominee.
            'person' => $this->person ? [
                'person_code' => $this->person->person_code,
                'full_name' => $this->person->full_name,
            ] : null,
            'nomination_source' => $this->nomination_source,
            'source_need' => $need ? [
                'id' => $need->uuid,
                'title' => $need->title,
                'category' => ['code' => $need->category->code, 'name' => $need->category->name],
                'priority' => $need->priority,
                'status' => $need->status,
            ] : null,
            'status' => $this->status,
            'nominated_at' => $this->nominated_at?->toIso8601String(),
            'nominated_by' => $this->nominator ? ['name' => $this->nominator->name] : null,
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removed_by' => $this->remover ? ['name' => $this->remover->name] : null,
        ];
    }
}
