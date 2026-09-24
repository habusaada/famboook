<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * national_id is deliberately omitted: docs/06-PERMISSIONS.md §39 states
 * a general person.view permission "must not automatically expose full
 * National ID" — dedicated field-level permissions/masking
 * (person.national-id.view / .view-masked) aren't implemented yet, so
 * this defaults to hidden per the documented default-deny principle
 * (§112) until that layer exists.
 */
class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->activeMembership;

        return [
            'person_code' => $this->person_code,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'birth_date' => $this->birth_date?->toDateString(),
            'mobile' => $this->mobile,
            'alternate_mobile' => $this->alternate_mobile,
            'alternate_mobile_owner_relation' => $this->alternate_mobile_owner_relation,
            'life_status' => $this->life_status,
            'is_active' => $this->is_active,
            'family_membership' => $this->when($membership, fn () => [
                'family_code' => $membership->family->family_code,
                'is_household_head' => $membership->is_household_head,
                'relationship_type' => $membership->relationshipType ? [
                    'id' => $membership->relationshipType->id,
                    'code' => $membership->relationshipType->code,
                    'name' => $membership->relationshipType->name,
                ] : null,
                'started_at' => $membership->started_at?->toDateString(),
            ]),
        ];
    }
}
