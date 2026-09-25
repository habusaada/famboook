<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            // null = unnamed administrative container.
            'name' => $this->name,
            // Own name, else the branches' names; null when neither exists.
            'display_name' => $this->relationLoaded('branches') ? $this->displayName() : $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
