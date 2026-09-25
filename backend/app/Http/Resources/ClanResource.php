<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Clan → Branch Groups → Branches (docs/02 §7a–§7c). Public UUIDs and
 * codes only; internal ids are never exposed. Family counts are derived.
 */
class ClanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'family_count' => $this->whenCounted('families'),
            'branch_groups' => BranchGroupResource::collection($this->whenLoaded('branchGroups')),
        ];
    }
}
