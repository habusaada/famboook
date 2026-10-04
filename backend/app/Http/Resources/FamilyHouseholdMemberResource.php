<?php

namespace App\Http\Resources;

use App\Models\FamilyMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One household member for the Family Portal (GET
 * /api/v1/family/household/members, PWA-3A): one row per active membership,
 * an explicit allow-list built from HouseholdReadModel::members().
 *
 * The relationship and the household-head flag belong to the MEMBERSHIP and
 * are always returned. The Person fields are returned only while the Person
 * exists: for a soft-deleted Person the row stays (the membership is still
 * active and counted) with available=false and no Person data at all.
 *
 * Never person_code, a National ID (in any form), a mobile, marital status,
 * notes, paper_sequence_no, health, audit, account or auth data, or any
 * internal id.
 *
 * @mixin FamilyMembership
 */
class FamilyHouseholdMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->person_deleted_at === null;

        return [
            'available' => $available,
            'full_name' => $available ? $this->person_full_name : null,
            'relationship' => $this->relationship_code === null ? null : [
                'code' => $this->relationship_code,
                'name' => $this->relationship_name,
            ],
            'is_household_head' => (bool) $this->is_household_head,
            'gender' => $available ? $this->person_gender : null,
            'birth_date' => $available && $this->person_birth_date !== null
                ? Carbon::parse($this->person_birth_date)->toDateString()
                : null,
            'life_status' => $available ? $this->person_life_status : null,
        ];
    }
}
