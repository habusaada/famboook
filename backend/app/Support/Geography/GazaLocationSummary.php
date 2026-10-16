<?php

namespace App\Support\Geography;

use App\Models\FamilyResidence;
use App\Models\GazaLocation;

/**
 * The display summaries of a residence's location references for the Staff
 * family response (FB-GEO-01 Stage 3.2, Stage 3.1 audit D-4):
 * {id, level, name, is_active, path: [{id, level, name, is_active}]} or null.
 *
 * Never source codes, landmark type codes, review flags, import history or
 * timestamps. Loads both locations (when not loaded yet) and their paths in
 * a constant number of queries for the residence, whatever the depth.
 */
final class GazaLocationSummary
{
    /** @return array{original: ?array<string, mixed>, current: ?array<string, mixed>} */
    public static function forResidence(FamilyResidence $residence): array
    {
        $residence->loadMissing(['originalLocation', 'currentLocation']);
        $locations = collect([$residence->originalLocation, $residence->currentLocation])
            ->filter()
            ->filter(fn (GazaLocation $location) => ! $location->relationLoaded('path'));
        GazaLocationPaths::attach($locations->values());

        return [
            'original' => self::present($residence->originalLocation),
            'current' => self::present($residence->currentLocation),
        ];
    }

    /** @return ?array<string, mixed> */
    private static function present(?GazaLocation $location): ?array
    {
        if ($location === null) {
            return null;
        }

        return [
            'id' => $location->id,
            'level' => $location->level->value,
            'name' => $location->displayName(),
            'is_active' => (bool) $location->is_active,
            'path' => $location->path->map(fn (GazaLocation $ancestor) => [
                'id' => $ancestor->id,
                'level' => $ancestor->level->value,
                'name' => $ancestor->displayName(),
                'is_active' => (bool) $ancestor->is_active,
            ])->values()->all(),
        ];
    }
}
