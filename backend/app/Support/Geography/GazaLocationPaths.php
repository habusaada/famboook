<?php

namespace App\Support\Geography;

use App\Models\GazaLocation;
use Illuminate\Support\Collection;

/**
 * Attaches each location's ancestor path (governorate first, parent last)
 * as the non-persisted `path` relation — FB-GEO-01 Stage 2B.
 *
 * Batched by depth: one query per ancestor level for the whole collection
 * (at most four, whatever the collection size), never one per location.
 * Only id, level, name, parent_id and is_active are read (is_active is
 * presented only where a contract exposes it: location details and
 * residence summaries, Stage 3.2 G1).
 */
final class GazaLocationPaths
{
    /** @param Collection<int, GazaLocation> $locations */
    public static function attach(Collection $locations): void
    {
        /** @var array<string, GazaLocation> $ancestors */
        $ancestors = [];
        $pending = $locations->pluck('parent_id')->filter()->unique()->values()->all();
        while ($pending !== []) {
            $fetched = GazaLocation::query()->whereIn('id', $pending)->get(['id', 'level', 'name', 'parent_id', 'is_active']);
            foreach ($fetched as $ancestor) {
                $ancestors[$ancestor->id] = $ancestor;
            }
            $pending = $fetched->pluck('parent_id')->filter(fn ($id) => $id !== null && ! isset($ancestors[$id]))->unique()->values()->all();
        }

        foreach ($locations as $location) {
            $path = [];
            for ($id = $location->parent_id; $id !== null; $id = $ancestors[$id]->parent_id) {
                array_unshift($path, $ancestors[$id]);
            }
            $location->setRelation('path', collect($path));
        }
    }
}
