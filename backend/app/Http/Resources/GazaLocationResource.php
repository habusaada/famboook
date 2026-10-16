<?php

namespace App\Http\Resources;

use App\Enums\GazaLocationLevel;
use App\Models\GazaLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Gaza location for Staff lookups (FB-GEO-01 Stage 2B, API-D4). An
 * explicit whitelist: never the source code, landmark type code, review
 * flags, import history, timestamps or any family data.
 *
 * - name: the display value (edge-trimmed); the stored source value is not
 *   changed (Stage 1B G5).
 * - has_children: whether the node has ACTIVE children (always loaded by
 *   GazaLocationController).
 * - landmark_type: the landmark type name for a LANDMARK, otherwise null.
 *   Staff responses only (API-D4): the Family PWA mirrors call
 *   forFamily(), which omits the key.
 * - path: ancestors from the governorate down to the parent, each
 *   {id, level, name}; present only where loaded (search, details).
 * - is_active: ONLY on the details endpoint (withStatus()), for the node and
 *   each path item — Stage 3.2 G1 (API-D4 amended), so a saved inactive
 *   selection can be shown historically. Lists, children and search return
 *   active locations only and never carry it.
 *
 * @mixin GazaLocation
 */
class GazaLocationResource extends JsonResource
{
    private bool $withStatus = false;

    private bool $forFamily = false;

    /** Details only: add is_active to the node and its path items. */
    public function withStatus(): static
    {
        $this->withStatus = true;

        return $this;
    }

    /** Family PWA mirrors (API-D2): the same whitelist without landmark_type. */
    public function forFamily(): static
    {
        $this->forFamily = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level->value,
            'name' => $this->displayName(),
            'parent_id' => $this->parent_id,
            'has_children' => (bool) $this->has_children,
            'landmark_type' => $this->unless($this->forFamily, fn () => $this->level === GazaLocationLevel::LANDMARK ? trim((string) $this->landmark_type_name) : null),
            'is_active' => $this->when($this->withStatus, fn () => (bool) $this->is_active),
            'path' => $this->whenLoaded('path', fn () => $this->path->map(fn (GazaLocation $ancestor) => [
                'id' => $ancestor->id,
                'level' => $ancestor->level->value,
                'name' => $ancestor->displayName(),
                ...($this->withStatus ? ['is_active' => (bool) $ancestor->is_active] : []),
            ])->values()->all()),
        ];
    }
}
