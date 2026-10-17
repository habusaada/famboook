<?php

namespace App\Support\Geography;

use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The display summaries of a residence's location references for the Staff
 * family response (FB-GEO-01 Stage 3.2, Stage 3.1 audit D-4):
 * {id, level, name, is_active, path: [{id, level, name, is_active}]} or null.
 *
 * Never source codes, landmark type codes, review flags, import history or
 * timestamps. Loads both locations (when not loaded yet) and their paths in
 * a constant number of queries for the residence, whatever the depth.
 *
 * forFamily (the Family household profile, FB-GEO-03) adds `landmark_type`:
 * the family-safe type name of a LANDMARK (FamilyLandmarkType), otherwise
 * null. The Staff summary is unchanged.
 */
final class GazaLocationSummary
{
    /** @return array{original: ?array<string, mixed>, current: ?array<string, mixed>} */
    public static function forResidence(FamilyResidence $residence, bool $forFamily = false): array
    {
        $residence->loadMissing(['originalLocation', 'currentLocation']);
        $locations = collect([$residence->originalLocation, $residence->currentLocation])
            ->filter()
            ->filter(fn (GazaLocation $location) => ! $location->relationLoaded('path'));
        GazaLocationPaths::attach($locations->values());
        if ($forFamily) {
            FamilyLandmarkType::load(new Collection(array_values(array_filter([$residence->originalLocation, $residence->currentLocation]))));
        }

        return [
            'original' => self::present($residence->originalLocation, $forFamily),
            'current' => self::present($residence->currentLocation, $forFamily),
        ];
    }

    /** @return ?array<string, mixed> */
    private static function present(?GazaLocation $location, bool $forFamily): ?array
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
            ...($forFamily ? ['landmark_type' => FamilyLandmarkType::of($location)] : []),
        ];
    }
}
