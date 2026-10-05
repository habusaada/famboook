<?php

namespace App\Http\Resources;

use App\Models\FamilyMembership;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\MobileMask;
use App\Support\NationalIdMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One household member for the Family Portal (GET
 * /api/v1/family/household/members; PWA-3A, completed by PWA-3B.3, docs/11
 * §23a): one row per active membership, an explicit allow-list built from
 * HouseholdReadModel::members().
 *
 * member_ref is the opaque reference of the MEMBERSHIP (FU-13,
 * HouseholdMemberReference): present on every row, placeholders included;
 * it grants nothing by itself.
 *
 * The relationship, the household-head flag and the membership start belong
 * to the MEMBERSHIP and are always returned. The Person fields are returned
 * only while the Person exists: for a soft-deleted Person the row stays (the
 * membership is still active and counted) with available=false and no
 * Person data at all.
 *
 * The National ID, mobile and alternate mobile are returned MASKED only
 * (NationalIdMask, MobileMask); the full values are a separate member reveal
 * (PWA-3B.4, docs/11 FU-13) and never part of this payload. Enums are
 * returned as stored (UNKNOWN stays UNKNOWN); NULL stays NULL.
 *
 * Never person_code, any internal id (Person, membership, relationship
 * type), paper_sequence_no, notes, is_active, health, audit, account or auth
 * data.
 *
 * @mixin FamilyMembership
 */
class FamilyHouseholdMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->person_deleted_at === null;
        $date = fn (?string $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString();
        $person = fn (mixed $value): mixed => $available ? $value : null;

        return [
            // The opaque membership reference (FU-13): computed, never an id.
            'member_ref' => HouseholdMemberReference::of((int) $this->family_id, (int) $this->id),
            'available' => $available,
            'full_name' => $person($this->person_full_name),
            'relationship' => $this->relationship_code === null ? null : [
                'code' => $this->relationship_code,
                'name' => $this->relationship_name,
            ],
            'is_household_head' => (bool) $this->is_household_head,
            'gender' => $person($this->person_gender),
            'birth_date' => $person($date($this->person_birth_date)),
            'marital_status' => $person($this->person_marital_status),
            'life_status' => $person($this->person_life_status),
            'death_date' => $person($date($this->person_death_date)),
            'national_id_masked' => $person(NationalIdMask::mask($this->person_national_id)),
            'mobile_masked' => $person(MobileMask::mask($this->person_mobile)),
            'alternate_mobile_masked' => $person(MobileMask::mask($this->person_alternate_mobile)),
            'alternate_mobile_owner_relation' => $person($this->person_alternate_mobile_owner_relation),
            'membership_started_at' => $date($this->membership_started_at),
        ];
    }
}
