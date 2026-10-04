<?php

namespace App\Http\Resources;

use App\Models\Family;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The «أسرتي» profile for the Family Portal (GET
 * /api/v1/family/household/profile, PWA-3A Step 4): an explicit allow-list
 * built from HouseholdReadModel::profile().
 *
 * family: the same facts as the household summary — the declared size from
 * the current declaration only (NULL = not declared), and
 * registered_member_count over the shared active-membership population.
 *
 * residence: the CURRENT residence, or NULL when there is none. Values are
 * returned as stored: NULL stays NULL. displacement_status NULL means "not
 * collected", never NOT_DISPLACED. original_residence_text is the residence
 * before displacement, never the current address.
 *
 * Never address_text, residence_type, coordinates, residence dates or flags,
 * sources, notes, status, registration data, paper_form_no, the declared
 * sons/daughters, any internal id, a National ID, a mobile, or auth data.
 *
 * @mixin Family
 */
class FamilyHouseholdProfileResource extends JsonResource
{
    public function __construct(Family $family, private readonly Person $head)
    {
        parent::__construct($family);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $declaration = $this->currentHouseholdDeclaration;
        $residence = $this->currentResidence;

        return [
            'family' => [
                'family_code' => $this->family_code,
                'clan_name' => $this->clan?->name,
                'branch_name' => $this->branch?->name,
                'head' => [
                    'full_name' => $this->head->full_name,
                ],
                'declared_household_size' => $declaration?->declared_household_size,
                'declared_at' => $declaration?->declared_at?->toDateString(),
                'registered_member_count' => (int) $this->registered_member_count,
            ],
            'residence' => $residence === null ? null : [
                'original_residence_text' => $residence->original_residence_text,
                'displacement_status' => $residence->displacement_status?->value,
                'displacement_location_text' => $residence->displacement_location_text,
                'current_address' => [
                    'governorate' => $residence->governorate,
                    'city' => $residence->city,
                    'area' => $residence->area,
                    'neighborhood' => $residence->neighborhood,
                ],
            ],
        ];
    }
}
