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
            // Always null until reference data (relationship_types) is
            // implemented — see docs/07-ROADMAP.md Phase 7. Present so
            // the frontend can distinguish "not yet supported" from
            // "absent field" without guessing.
            'relationship_type_id' => $this->relationship_type_id,
        ];
    }
}
