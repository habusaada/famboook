<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Need for need.view holders (family list, global work queue, detail).
 * Only safe identity is included: family code and household-head name,
 * the targeted person's code and name, and the source assessment's UUID,
 * date and status — never its notes or results. No National ID, phone
 * numbers, health records, internal ids or user emails.
 */
class NeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
            ],
            // null = the whole family.
            'person' => $this->person ? [
                'person_code' => $this->person->person_code,
                'full_name' => $this->person->full_name,
            ] : null,
            'category' => new NeedCategoryResource($this->category),
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'quantity' => $this->quantity === null ? null : self::trimDecimal($this->quantity),
            'unit' => $this->unit,
            'status' => $this->status,
            'source_assessment' => $this->sourceAssessment ? [
                'id' => $this->sourceAssessment->uuid,
                'assessment_date' => $this->sourceAssessment->assessment_date->toDateString(),
                'status' => $this->sourceAssessment->status,
            ] : null,
            'created_by' => $this->creator ? ['name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->resolver ? ['name' => $this->resolver->name] : null,
            'closure_reason' => $this->closure_reason,
        ];
    }

    /** "5.00" → "5", "2.50" → "2.5". */
    private static function trimDecimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** Relations every Need response needs, for eager loading. */
    public const RELATIONS = [
        'family.householdHeadMembership.person',
        'person',
        'category',
        'sourceAssessment',
        'creator:id,name',
        'resolver:id,name',
    ];
}
