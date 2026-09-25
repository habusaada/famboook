<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape required by the existing /families registry screen table:
 * family code, household head, member count, status, last update.
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
            'registration_date' => $this->registration_date?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
