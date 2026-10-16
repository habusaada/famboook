<?php

namespace App\Support\Geography;

use App\Enums\GazaLocationLevel;
use App\Models\GazaLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * The read-only Gaza reference queries shared by the Staff lookups
 * (GazaLocationController) and the Family PWA mirrors
 * (Family\FamilyGazaLocationController, FB-GEO-01 API-D2), so both audiences
 * see exactly the same hierarchy. Authorization stays in each controller.
 *
 * Lists and children: ACTIVE locations only. Details: any status. Review
 * flags and import metadata are never read. Nothing is written.
 */
final class GazaLocationLookup
{
    /** Defensive bound on one node's children (largest in the source: 73). */
    public const MAX_CHILDREN = 500;

    /** The governorates, north to south (official codes 41 → 45). */
    public static function governorates(): Collection
    {
        return self::active()
            ->where('level', GazaLocationLevel::GOVERNORATE->value)
            // Codes are verified digits (import); ordering only, never exposed.
            ->orderByRaw('CAST(source_code AS INTEGER)')
            ->orderBy('id')
            ->get();
    }

    /**
     * Active immediate children of an ACTIVE location (a landmark has none);
     * an inactive parent is a 404 for every audience.
     */
    public static function children(GazaLocation $location): Collection
    {
        abort_unless($location->is_active, 404);

        $children = self::byName(self::active()->where('parent_id', $location->id))
            ->limit(self::MAX_CHILDREN + 1)
            ->get();
        if ($children->count() > self::MAX_CHILDREN) {
            // A source revision outgrew the bound: fail loudly, never truncate silently.
            throw new LogicException('A Gaza location has more children than GazaLocationLookup::MAX_CHILDREN.');
        }

        return $children;
    }

    /** One location (active or not) with has_children and its ancestor path. */
    public static function details(GazaLocation $location): GazaLocation
    {
        $location->loadExists(['children as has_children' => fn ($q) => $q->where('is_active', true)]);
        GazaLocationPaths::attach(collect([$location]));

        return $location;
    }

    /** Active locations, with whether each has active children. */
    public static function active(): Builder
    {
        return GazaLocation::query()
            ->where('is_active', true)
            ->withExists(['children as has_children' => fn ($q) => $q->where('is_active', true)]);
    }

    public static function byName(Builder $query): Builder
    {
        return $query->orderByRaw('TRIM(name)')->orderBy('id');
    }
}
