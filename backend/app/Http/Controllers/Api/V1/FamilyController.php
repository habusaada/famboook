<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterFamilyAction;
use App\Actions\UpdateFamilyAction;
use App\Enums\FamilyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterFamilyRequest;
use App\Http\Requests\Api\V1\UpdateFamilyRequest;
use App\Http\Resources\FamilyDetailResource;
use App\Http\Resources\FamilySummaryResource;
use App\Models\Family;
use App\Support\RegistrySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
    public const MAX_PER_PAGE = 100;

    /**
     * Server-side Family registry (docs/03 §93a): `search` matches the
     * Family code, or the name / Person code of any current member (plain
     * "contains", case-insensitive); optional `status`. Paginated. The
     * `summary` counts the whole registry by status, independent of the
     * search, so no card ever shows a page-sized number as a total.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:'.RegistrySearch::MAX_TERM],
            'status' => ['sometimes', 'nullable', Rule::enum(FamilyStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $term = trim((string) ($validated['search'] ?? ''));

        $families = Family::query()
            ->with(['householdHeadMembership.person', 'clan:id,name', 'branch:id,name'])
            ->withCount('memberships')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('families.status', $status))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => RegistrySearch::contains($w, 'families.family_code', $term)
                ->orWhereIn('families.id', DB::table('family_memberships as sm')
                    ->join('persons as sp', 'sp.id', '=', 'sm.person_id')
                    ->where('sm.is_active', true)
                    ->whereNull('sp.deleted_at')
                    ->where(fn ($p) => RegistrySearch::contains($p, 'sp.full_name', $term)
                        ->orWhere(fn ($c) => RegistrySearch::contains($c, 'sp.person_code', $term)))
                    ->select('sm.family_id'))))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $byStatus = Family::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return FamilySummaryResource::collection($families)->additional(['summary' => [
            'total' => (int) $byStatus->sum(),
            ...collect(FamilyStatus::cases())->mapWithKeys(fn (FamilyStatus $s) => [strtolower($s->value) => (int) ($byStatus[$s->value] ?? 0)]),
        ]]);
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
        $family->load(['memberships.person', 'memberships.relationshipType', 'currentResidence', 'clan', 'branch.group.branches']);

        return new FamilyDetailResource($family);
    }

    public function update(UpdateFamilyRequest $request, Family $family, UpdateFamilyAction $action): FamilyDetailResource
    {
        $family = $action->handle($family, $request->validated(), $request->user()?->id);

        return new FamilyDetailResource($family);
    }
}
