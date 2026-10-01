<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape required by the existing /families registry screen table:
 * family code, household head, member count, status, last update.
 *
 * Two different figures (docs/02 §20a, docs/03 §55): `member_count` is the
 * number of ACTIVE memberships — Persons registered individually;
 * `declared_household_size` is the household's DECLARED total from its
 * current declaration (NULL when none). Neither is derived from the other.
 */
class FamilySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'family_code' => $this->family_code,
            // Compact: names only for the list.
            'clan_name' => $this->clan?->name,
            'branch_name' => $this->branch?->name,
            'status' => $this->status,
            'household_head_name' => $this->householdHeadMembership?->person?->full_name,
            'member_count' => $this->memberships_count ?? $this->memberships->count(),
            'declared_household_size' => $this->currentHouseholdDeclaration?->declared_household_size,
            'registration_date' => $this->registration_date?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
