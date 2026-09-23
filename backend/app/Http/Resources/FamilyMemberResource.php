<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'person_code' => $this->person->person_code,
            'full_name' => $this->person->full_name,
            'gender' => $this->person->gender,
            'birth_date' => $this->person->birth_date?->toDateString(),
            'is_household_head' => $this->is_household_head,
            'is_active' => $this->is_active,
            // Null for legacy memberships created before this slice, or
            // where the relationship was never recorded — the frontend
            // must not guess a label for these (docs task J).
            'relationship_type' => $this->relationshipType ? [
                'id' => $this->relationshipType->id,
                'code' => $this->relationshipType->code,
                'name' => $this->relationshipType->name,
            ] : null,
        ];
    }
}
