<?php

namespace App\Http\Resources;

use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Family in scope as the Coordinator members directory shows it (CO-01C,
 * coordinator-member.view, docs/06 AUTH-ADR-092): an explicit allow-list —
 * the code, the hierarchy names, the head's display name, the REGISTERED
 * member count (active memberships) and, separately, the DECLARED household
 * size of the current declaration with its date. The two counts are never
 * reconciled or derived from one another.
 *
 * Never residence, health, needs, assistance, notes, documents, account or
 * activation status, registration source or any internal id.
 *
 * @mixin Family
 */
class CoordinatorFamilyDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $declaration = $this->currentHouseholdDeclaration;

        return [
            'family_code' => $this->family_code,
            'clan_name' => $this->clan?->name,
            'branch_group_name' => $this->branch?->group?->name,
            'branch_name' => $this->branch?->name,
            'head_name' => $this->householdHeadMembership?->person?->full_name,
            'registered_member_count' => (int) $this->registered_member_count,
            'declared_household_size' => $declaration?->declared_household_size,
            'declared_at' => $declaration?->declared_at?->toDateString(),
        ];
    }
}
