<?php

namespace App\Http\Resources;

use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Family as a Coordinator may see it (docs/06 §22b,
 * coordinator-family.view-summary): SUMMARY ONLY, an explicit allow-list —
 * the family code, where it sits in the hierarchy, the head's display name
 * and how many active members it has.
 *
 * Never a National ID (in any form), a mobile or contact detail, residence,
 * health, disability, needs, assistance, notes, documents, account or
 * activation status, auth data, an internal id or any other Person field.
 * The model is never serialized as such.
 *
 * @mixin Family
 */
class CoordinatorFamilySummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'family_code' => $this->family_code,
            'clan_name' => $this->clan?->name,
            'branch_group_name' => $this->branch?->group?->name,
            'branch_name' => $this->branch?->name,
            'head_name' => $this->householdHeadMembership?->person?->full_name,
            'active_member_count' => (int) $this->active_member_count,
        ];
    }
}
