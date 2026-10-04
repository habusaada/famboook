<?php

namespace App\Http\Resources;

use App\Models\Family;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The household summary for the Family Portal dashboard
 * (GET /api/v1/family/household, PWA-3A): an explicit allow-list.
 *
 * - head is the signed-in household head, as the resolver found them;
 * - declared_household_size and declared_at come from the current
 *   declaration only — NULL when nothing was declared, a stored 0 stays 0;
 * - registered_member_count is the number of active memberships.
 *
 * Never an internal id, a National ID (in any form), a mobile, notes, the
 * paper form number, the registration source, residence, health, needs or
 * coordinator data. The model is never serialized as such.
 *
 * @mixin Family
 */
class FamilyHouseholdResource extends JsonResource
{
    public function __construct(Family $family, private readonly Person $head)
    {
        parent::__construct($family);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $declaration = $this->currentHouseholdDeclaration;

        return [
            'family_code' => $this->family_code,
            'clan_name' => $this->clan?->name,
            'branch_name' => $this->branch?->name,
            'head' => [
                'full_name' => $this->head->full_name,
            ],
            'declared_household_size' => $declaration?->declared_household_size,
            'declared_at' => $declaration?->declared_at?->toDateString(),
            'registered_member_count' => (int) $this->registered_member_count,
        ];
    }
}
