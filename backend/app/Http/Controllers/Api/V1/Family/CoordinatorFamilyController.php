<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\CoordinatorFamilyIndexRequest;
use App\Http\Resources\CoordinatorFamilySummaryResource;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Families a Coordinator may consider (docs/11 §8, docs/06 §22b), as
 * summaries only. Every query starts from CoordinatorScopes::families() with
 * the context the `coordinator.space` boundary resolved; search and filters
 * are added on top and can only narrow it. There is no route-model binding
 * and no unrestricted Family lookup: a Family outside the scope and a Family
 * that does not exist are the same 404.
 */
class CoordinatorFamilyController extends Controller
{
    public const PER_PAGE = 25;

    public const NOT_FOUND = ['message' => 'الأسرة غير متاحة.', 'code' => 'FAMILY_NOT_FOUND'];

    public function __construct(private readonly CoordinatorScopes $scopes) {}

    public function index(CoordinatorFamilyIndexRequest $request): JsonResponse
    {
        $query = $this->summaries($request);

        if ($q = $request->search()) {
            $query->where(fn (Builder $w) => $w
                ->where('families.family_code', 'like', strtoupper(addcslashes($q, '%_\\')).'%')
                ->orWhereHas('householdHeadMembership.person', fn (Builder $p) => $p->where('full_name', 'like', '%'.addcslashes($q, '%_\\').'%')));
        }
        if ($clan = $request->input('clan')) {
            $query->whereHas('clan', fn (Builder $c) => $c->where('code', $clan));
        }
        if ($group = $request->input('branch_group')) {
            $query->whereHas('branch.group', fn (Builder $g) => $g->where('code', $group));
        }
        if ($branch = $request->input('branch')) {
            $query->whereHas('branch', fn (Builder $b) => $b->where('code', $branch));
        }

        $page = $query->orderBy('families.family_code')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => CoordinatorFamilySummaryResource::collection($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $familyCode): JsonResponse
    {
        // Only inside the authorized query: out of scope = does not exist.
        $family = $this->summaries($request)->where('families.family_code', $familyCode)->first();

        if ($family === null) {
            return response()->json(self::NOT_FOUND, 404)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => new CoordinatorFamilySummaryResource($family)])->header('Cache-Control', 'no-store');
    }

    /** The authorized Families with exactly what the summary needs loaded. */
    private function summaries(Request $request): Builder
    {
        return $this->scopes->families(CoordinatorSpaceController::coordinator($request))
            ->select(['families.id', 'families.family_code', 'families.clan_id', 'families.branch_id'])
            ->with([
                'clan:id,name',
                'branch:id,name,branch_group_id',
                'branch.group:id,name',
                'householdHeadMembership:id,family_id,person_id,is_household_head,is_active',
                'householdHeadMembership.person:id,full_name',
            ])
            ->withCount(['memberships as active_member_count' => fn (Builder $m) => $m->where('is_active', true)]);
    }
}
