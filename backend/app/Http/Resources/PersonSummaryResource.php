<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A People registry row (docs/03 §93a): codes, name, gender, date of birth
 * (NULL = unknown), life status and the current Family context — the
 * latter only for users who may view Families. Never the National ID,
 * contact details, health data or internal ids.
 *
 * @mixin \App\Models\Person
 */
class PersonSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->activeMembership;
        $withFamily = $membership !== null && ($request->user()?->can('family.view') ?? false);

        return [
            'person_code' => $this->person_code,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'life_status' => $this->life_status,
            'family' => $withFamily ? [
                'family_code' => $membership->family->family_code,
                'is_household_head' => $membership->is_household_head,
                'relationship' => $membership->relationshipType ? [
                    'code' => $membership->relationshipType->code,
                    'name' => $membership->relationshipType->name,
                ] : null,
                'branch_name' => $membership->family->branch?->name,
            ] : null,
        ];
    }
}
