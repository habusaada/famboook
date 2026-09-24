<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Assistance program for assistance.view holders. The nominee count is
 * derived (withCount of current nominees), never stored.
 */
class AssistanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'category' => new AssistanceCategoryResource($this->category),
            'assistance_type' => $this->assistance_type,
            'provider_name' => $this->provider_name,
            'target_beneficiaries' => $this->target_beneficiaries,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status,
            'nominee_count' => $this->whenCounted('current_nominees'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'item_name' => $item->item_name,
                'quantity_per_beneficiary' => self::trim($item->quantity_per_beneficiary),
                'unit' => $item->unit,
                'unit_value' => self::trim($item->unit_value),
                'currency' => $item->currency,
            ])),
            'targeting_criteria' => $this->targeting_criteria ?? (object) [],
            'created_by' => $this->creator ? ['name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opened_by' => $this->opener ? ['name' => $this->opener->name] : null,
        ];
    }

    /** "4.00" → "4", "2.50" → "2.5". */
    private static function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
