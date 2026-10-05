<?php

namespace App\Http\Resources;

use App\Models\FamilyMembership;
use App\Support\MobileMask;
use App\Support\NationalIdMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * «بياناتي الشخصية» for the Family Portal (GET /api/v1/family/self,
 * PWA-3B.1, docs/11 §23a): the signed-in household head's own Person and
 * membership data — an explicit allow-list built from
 * HouseholdReadModel::self().
 *
 * The National ID, mobile and alternate mobile leave the server MASKED only
 * (NationalIdMask, MobileMask); NULL stays NULL. The full values are a
 * separate, dedicated reveal (PWA-3B.2) and are never part of this payload.
 * Enums are returned as stored (marital_status UNKNOWN stays UNKNOWN); the
 * age is derived by the client from birth_date, as in the members list.
 *
 * Never person_code, life status internals, notes, is_active, audit, account,
 * trust, OTP or auth data, or any internal id.
 *
 * @mixin FamilyMembership
 */
class FamilySelfResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $date = fn (?string $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString();

        return [
            'full_name' => $this->person_full_name,
            'national_id_masked' => NationalIdMask::mask($this->person_national_id),
            'gender' => $this->person_gender,
            'birth_date' => $date($this->person_birth_date),
            'marital_status' => $this->person_marital_status,
            'mobile_masked' => MobileMask::mask($this->person_mobile),
            'alternate_mobile_masked' => MobileMask::mask($this->person_alternate_mobile),
            'alternate_mobile_owner_relation' => $this->person_alternate_mobile_owner_relation,
            'relationship' => $this->relationship_code === null ? null : [
                'code' => $this->relationship_code,
                'name' => $this->relationship_name,
            ],
            'is_household_head' => (bool) $this->is_household_head,
            'membership_started_at' => $date($this->membership_started_at),
        ];
    }
}
