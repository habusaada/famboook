<?php

namespace App\Http\Resources;

use App\Models\Family;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The «أسرتي» record for the Family Portal (GET
 * /api/v1/family/household/profile; PWA-3A Step 4, completed by PWA-3B.3,
 * docs/11 §23a): an explicit allow-list built from
 * HouseholdReadModel::profile(). Values are returned as stored: NULL stays
 * NULL, and 0 stays 0.
 *
 * family: code, clan, branch group (NULL when the branch has none or it has
 * no name of its own), branch, head, registration date, paper form number,
 * and registered_member_count over the shared active-membership population.
 *
 * declaration: the CURRENT declaration, or NULL when there is none — never a
 * historical row. The declared counts are source facts, returned as stored:
 * nothing is derived from, or compared with, the registered members.
 *
 * residence: the CURRENT residence, or NULL when there is none (no history
 * exists: corrections are made in place). displacement_status NULL means
 * "not collected", never NOT_DISPLACED; original_residence_text is the
 * residence before displacement, never the current address.
 *
 * Never notes, status, registration source, coordinates, residence source or
 * flags, the declaration id or lifecycle, any internal id, person_code, a
 * National ID, a mobile or auth data.
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
                'branch_group_name' => $this->branch?->group?->name,
                'branch_name' => $this->branch?->name,
                'head' => [
                    'full_name' => $this->head->full_name,
                ],
                'registration_date' => $this->registration_date?->toDateString(),
                'paper_form_no' => $this->paper_form_no,
                'registered_member_count' => (int) $this->registered_member_count,
            ],
            'declaration' => $declaration === null ? null : [
                'declared_household_size' => $declaration->declared_household_size,
                'declared_living_sons' => $declaration->declared_living_sons,
                'declared_living_daughters' => $declaration->declared_living_daughters,
                'declared_at' => $declaration->declared_at?->toDateString(),
                'source' => $declaration->source?->value,
            ],
            'residence' => $residence === null ? null : [
                'original_residence_text' => $residence->original_residence_text,
                'displacement_status' => $residence->displacement_status?->value,
                'displacement_location_text' => $residence->displacement_location_text,
                'residence_type' => $residence->residence_type,
                'started_at' => $residence->started_at?->toDateString(),
                'current_address' => [
                    'governorate' => $residence->governorate,
                    'city' => $residence->city,
                    'area' => $residence->area,
                    'neighborhood' => $residence->neighborhood,
                    'address_text' => $residence->address_text,
                ],
            ],
        ];
    }
}
