<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GazaLocationLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\GazaLocationResource;
use App\Models\GazaLocation;
use App\Support\Geography\GazaLocationLookup;
use App\Support\Geography\GazaLocationPaths;
use App\Support\RegistrySearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only Staff lookups of the Gaza geographic reference (FB-GEO-01 Stage
 * 2B; contract: docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md).
 *
 * Authorization (API-D1): reference-data.view OR residence.update, checked
 * here like the other union reference endpoints (ReferenceController).
 * The routes sit in the auth:sanctum + staff.side group, so a family-side
 * account (FAMILY_USER, COORDINATOR) never reaches them.
 *
 * Lists, children and search return ACTIVE locations only; details returns
 * a location whatever its status. Flagged locations are ordinary active
 * locations here (Stage 1B Q1): review flags are never read. Nothing is
 * written. The queries are shared with the Family PWA mirrors
 * (GazaLocationLookup).
 */
class GazaLocationController extends Controller
{
    /** API-D1: holding either is enough. */
    public const READ_PERMISSIONS = ['reference-data.view', 'residence.update'];

    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public const MAX_CHILDREN = GazaLocationLookup::MAX_CHILDREN;

    /** The governorates, north to south (official codes 41 → 45). */
    public function governorates(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        return GazaLocationResource::collection(GazaLocationLookup::governorates());
    }

    /** Active immediate children of an active location (a landmark has none). */
    public function children(Request $request, GazaLocation $location): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        return GazaLocationResource::collection(GazaLocationLookup::children($location));
    }

    /**
     * Case-insensitive "contains" search on the name (RegistrySearch; no
     * Arabic normalization, PDD-003). parent_id limits the search to ALL
     * descendants of that location (not only its children). Paginated.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:'.RegistrySearch::MAX_TERM],
            'level' => ['sometimes', 'nullable', Rule::enum(GazaLocationLevel::class)],
            // `uuid` first (bail): a malformed value never reaches the uuid column.
            'parent_id' => ['sometimes', 'nullable', 'bail', 'uuid', Rule::exists('gaza_locations', 'id')->where('is_active', true)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $term = trim($validated['q']);

        $page = GazaLocationLookup::active()
            ->where(fn (Builder $w) => RegistrySearch::contains($w, 'gaza_locations.name', $term))
            ->when($validated['level'] ?? null, fn (Builder $q, string $level) => $q->where('level', $level))
            ->when($validated['parent_id'] ?? null, fn (Builder $q, string $parentId) => $q->whereRaw(
                'gaza_locations.id IN (WITH RECURSIVE scope(id) AS ('
                .'SELECT id FROM gaza_locations WHERE parent_id = ? '
                .'UNION ALL SELECT child.id FROM gaza_locations child JOIN scope ON child.parent_id = scope.id'
                .') SELECT id FROM scope)',
                [$parentId],
            ))
            // Broader levels first, then name, then id: deterministic.
            ->orderByRaw("CASE level WHEN 'GOVERNORATE' THEN 1 WHEN 'CITY' THEN 2 WHEN 'AREA' THEN 3 WHEN 'NEIGHBORHOOD' THEN 4 ELSE 5 END")
            ->orderByRaw('TRIM(name)')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString();

        GazaLocationPaths::attach($page->getCollection());

        return GazaLocationResource::collection($page);
    }

    /**
     * One location (active or not) with its ancestor path. The only response
     * carrying is_active (node and path items, Stage 3.2 G1), so a saved
     * inactive selection can be displayed without being offered again.
     */
    public function show(Request $request, GazaLocation $location): GazaLocationResource
    {
        $this->authorizeRead($request);

        return (new GazaLocationResource(GazaLocationLookup::details($location)))->withStatus();
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()->canAny(self::READ_PERMISSIONS), 403);
    }
}
