<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'family_count' => $this->whenCounted('families'),
            'group' => $this->whenLoaded('group', fn () => [
                'id' => $this->group->uuid,
                'code' => $this->group->code,
                'name' => $this->group->name,
            ]),
        ];
    }
}
