<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CloseNeedAction;
use App\Actions\CreateNeedAction;
use App\Actions\FulfillNeedAction;
use App\Actions\UpdateNeedAction;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseNeedRequest;
use App\Http\Requests\Api\V1\StoreNeedRequest;
use App\Http\Requests\Api\V1\UpdateNeedRequest;
use App\Http\Resources\NeedResource;
use App\Models\Family;
use App\Models\FamilyNeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Needs V1 (docs/06-PERMISSIONS.md §49, need.* permissions only). There
 * is deliberately no delete endpoint and no generic status PATCH:
 * resolution happens only through fulfill/close.
 */
class NeedController extends Controller
{
    /** One family's Needs, with a derived summary. */
    public function familyIndex(Request $request, Family $family): AnonymousResourceCollection
    {
        $needs = $this->filtered($request, $family->needs()->getQuery())
            ->paginate($this->perPage($request));

        $counts = $family->needs()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return NeedResource::collection($needs)->additional([
            // Derived on read, never stored.
            'summary' => [
                'open' => (int) ($counts[NeedStatus::OPEN->value] ?? 0),
                'urgent_open' => $family->needs()
                    ->where('status', NeedStatus::OPEN)
                    ->where('priority', NeedPriority::URGENT)
                    ->count(),
                'fulfilled' => (int) ($counts[NeedStatus::FULFILLED->value] ?? 0),
                'closed' => (int) ($counts[NeedStatus::CLOSED->value] ?? 0),
            ],
            // UX hint only; every write is re-authorized by its request.
            'abilities' => ['create' => $request->user()->can('need.create')],
        ]);
    }

    /** Cross-family work queue (Staff App /needs). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $needs = $this->filtered($request, FamilyNeed::query())
            ->paginate($this->perPage($request));

        return NeedResource::collection($needs);
    }

    public function store(StoreNeedRequest $request, Family $family, CreateNeedAction $action): JsonResponse
    {
        $need = $action->handle($family, $request->validated(), $request->user()?->id);

        return $this->detail($request, $need)->response()->setStatusCode(201);
    }

    public function show(Request $request, FamilyNeed $need): NeedResource
    {
        return $this->detail($request, $need);
    }

    public function update(UpdateNeedRequest $request, FamilyNeed $need, UpdateNeedAction $action): NeedResource
    {
        return $this->detail($request, $action->handle($need, $request->validated(), $request->user()?->id));
    }

    public function fulfill(Request $request, FamilyNeed $need, FulfillNeedAction $action): NeedResource
    {
        return $this->detail($request, $action->handle($need, $request->user()?->id));
    }

    public function close(CloseNeedRequest $request, FamilyNeed $need, CloseNeedAction $action): NeedResource
    {
        return $this->detail($request, $action->handle($need, $request->validated('closure_reason'), $request->user()?->id));
    }

    private function detail(Request $request, FamilyNeed $need): NeedResource
    {
        $need->load(NeedResource::RELATIONS);
        $user = $request->user();
        $open = $need->isOpen();

        return (new NeedResource($need))->additional([
            // UX hints only; a resolved Need offers no actions.
            'abilities' => [
                'update' => $open && $user->can('need.update'),
                'fulfill' => $open && $user->can('need.close'),
                'close' => $open && $user->can('need.close'),
            ],
        ]);
    }

    /**
     * Simple V1 filters (status, priority, category, target) and the
     * default order: OPEN first, then URGENT → LOW, then newest.
     */
    private function filtered(Request $request, Builder $query): Builder
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(NeedStatus::class)],
            'priority' => ['sometimes', Rule::enum(NeedPriority::class)],
            'category' => ['sometimes', 'string', 'max:50'],
            'target' => ['sometimes', Rule::in(['family', 'person'])],
        ]);

        return $query
            ->with(NeedResource::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['category'] ?? null, fn ($q, $code) => $q->whereHas('category', fn ($c) => $c->where('code', $code)))
            ->when(($filters['target'] ?? null) === 'family', fn ($q) => $q->whereNull('person_id'))
            ->when(($filters['target'] ?? null) === 'person', fn ($q) => $q->whereNotNull('person_id'))
            ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
            ->orderByRaw(NeedPriority::orderSql())
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), 50);
    }
}
