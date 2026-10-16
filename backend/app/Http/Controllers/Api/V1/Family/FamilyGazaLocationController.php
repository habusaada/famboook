<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Resources\GazaLocationResource;
use App\Models\GazaLocation;
use App\Support\Geography\GazaLocationLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Read-only Family PWA mirrors of the Gaza geographic reference (FB-GEO-01
 * API-D2; contract: docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md
 * §5.7), for the residence change-request form.
 *
 * The routes sit in the family group (auth:sanctum + family.side +
 * can:family-portal.access + family.context), so only a household member
 * with a Family context reaches them; Staff and coordinator-only accounts do
 * not. No permission was added: family-portal.access is FAMILY_USER's.
 *
 * The same queries and resource as the Staff lookups (GazaLocationLookup,
 * GazaLocationResource), without landmark_type (API-D4). Reference data
 * only: no family data, review flags or import metadata. Nothing is written.
 * no-store, like the other family reference routes.
 */
class FamilyGazaLocationController extends Controller
{
    public function governorates(): JsonResponse
    {
        return $this->list(GazaLocationLookup::governorates());
    }

    public function children(GazaLocation $location): JsonResponse
    {
        return $this->list(GazaLocationLookup::children($location));
    }

    /** Any status, with is_active (node and path): a saved inactive value can be shown, never offered. */
    public function show(GazaLocation $location): JsonResponse
    {
        return (new GazaLocationResource(GazaLocationLookup::details($location)))
            ->forFamily()
            ->withStatus()
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    private function list(Collection $locations): JsonResponse
    {
        return response()->json([
            'data' => $locations->map(fn (GazaLocation $location) => (new GazaLocationResource($location))->forFamily()->resolve())->values(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
