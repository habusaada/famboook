<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardRequest;
use App\Http\Resources\ClanResource;
use App\Models\Clan;
use App\Support\Dashboard\OperationalDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Operational Dashboard V1 (docs/06 §59a, permission
 * dashboard.view-operational). Read-only aggregates derived on request.
 */
class DashboardController extends Controller
{
    /**
     * The organizational scopes the dashboard can be filtered by: active
     * Clans with their Branch Groups and Branches. Inactive groups and
     * branches are included (flagged) because families may still be
     * assigned to them. Served here so dashboard viewers do not need
     * clan.view.
     */
    public function scopeOptions(): AnonymousResourceCollection
    {
        return ClanResource::collection(
            Clan::query()
                ->where('is_active', true)
                ->with('branchGroups.branches')
                ->orderBy('name')
                ->get()
        );
    }

    public function show(DashboardRequest $request): JsonResponse
    {
        $dashboard = new OperationalDashboard($request->scope(), $request->user(), today());

        return response()->json(['data' => $dashboard->toArray($request)]);
    }
}
