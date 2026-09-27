<?php

namespace App\Http\Resources;

use App\Support\NationalIdMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full National ID is never returned: docs/06-PERMISSIONS.md §39 states
 * a general person.view permission "must not automatically expose full
 * National ID", and person.national-id.view (FULL) is unassigned.
 *
 * Holders of person.national-id.view-masked (AUTH-ADR-059) receive
 * `national_id_masked` from the central NationalIdMask rule — NULL when no
 * National ID is recorded. For everyone else the key is omitted (HIDDEN,
 * §36), so the response never reveals whether a National ID exists.
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
            'national_id_masked' => $this->when(
                $request->user()?->can('person.national-id.view-masked') ?? false,
                fn () => NationalIdMask::mask($this->national_id),
            ),
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
