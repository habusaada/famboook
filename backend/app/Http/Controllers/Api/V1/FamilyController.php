<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterFamilyAction;
use App\Actions\UpdateFamilyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterFamilyRequest;
use App\Http\Requests\Api\V1\UpdateFamilyRequest;
use App\Http\Resources\FamilyDetailResource;
use App\Http\Resources\FamilySummaryResource;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Object-level authorization is not yet implemented (docs/06-PERMISSIONS.md
 * §140: staff Family access is "Scope"/"ALL" — full Data Scope is a later
 * phase). These endpoints are gated only by the route-level `can:` Spatie
 * permission middleware (family.view / family.create): any authenticated
 * user holding that permission can see/create any Family. See the task
 * report for this documented gap.
 */
class FamilyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $families = Family::query()
            ->with('householdHeadMembership.person')
            ->withCount('memberships')
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return FamilySummaryResource::collection($families);
    }

    public function store(RegisterFamilyRequest $request, RegisterFamilyAction $action): JsonResponse
    {
        $family = $action->handle($request->validated(), $request->user()?->id);

        return (new FamilyDetailResource($family))
            ->additional(['message' => 'تم تسجيل الأسرة بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Family $family): FamilyDetailResource
    {
        $family->load(['memberships.person', 'memberships.relationshipType', 'currentResidence']);

        return new FamilyDetailResource($family);
    }

    public function update(UpdateFamilyRequest $request, Family $family, UpdateFamilyAction $action): FamilyDetailResource
    {
        $family = $action->handle($family, $request->validated(), $request->user()?->id);

        return new FamilyDetailResource($family);
    }
}
