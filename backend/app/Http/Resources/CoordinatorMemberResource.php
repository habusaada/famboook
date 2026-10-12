<?php

namespace App\Http\Resources;

use App\Enums\LifeStatus;
use App\Models\FamilyMembership;
use App\Support\Coordinator\CoordinatorMemberReference;
use App\Support\MobileMask;
use App\Support\NationalIdMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One member of a Family in scope for the Coordinator members directory
 * (CO-01C, coordinator-member.view, docs/06 AUTH-ADR-092): one row per ACTIVE
 * membership, an explicit allow-list built from
 * CoordinatorMemberDirectory::members().
 *
 * - member_ref: the Coordinator opaque reference of the MEMBERSHIP; it
 *   grants nothing by itself.
 * - The relationship and the head flag belong to the membership and are
 *   always returned; the Person fields only while the Person exists (a
 *   soft-deleted Person is a placeholder: available=false, no Person data).
 * - Age, not the birth date: whole years today; NULL when the birth date is
 *   unknown or the member is DECEASED (no current age).
 * - The National ID and the mobile are MASKED only (NationalIdMask,
 *   MobileMask). The full values are the separate, audited reveal and are
 *   never part of this payload.
 *
 * Never person_code, any internal id, the birth date, the alternate mobile,
 * residence, health, disability, needs, assistance, notes, documents,
 * account, OTP or auth data.
 *
 * @mixin FamilyMembership
 */
class CoordinatorMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->person_deleted_at === null;
        $person = fn (mixed $value): mixed => $available ? $value : null;

        return [
            'member_ref' => CoordinatorMemberReference::of((int) $this->family_id, (int) $this->id),
            'available' => $available,
            'full_name' => $person($this->person_full_name),
            'relationship' => $this->relationship_code === null ? null : [
                'code' => $this->relationship_code,
                'name' => $this->relationship_name,
            ],
            'is_household_head' => (bool) $this->is_household_head,
            'gender' => $person($this->person_gender),
            'age' => $person($this->age()),
            'marital_status' => $person($this->person_marital_status),
            'life_status' => $person($this->person_life_status),
            'national_id_masked' => $person(NationalIdMask::mask($this->person_national_id)),
            'mobile_masked' => $person(MobileMask::mask($this->person_mobile)),
        ];
    }

    private function age(): ?int
    {
        if ($this->person_birth_date === null || $this->person_life_status === LifeStatus::DECEASED->value) {
            return null;
        }

        return max(0, (int) Carbon::parse($this->person_birth_date)->age);
    }
}
