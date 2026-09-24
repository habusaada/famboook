<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateAssistanceAction;
use App\Actions\OpenAssistanceAction;
use App\Actions\UpdateAssistanceAction;
use App\Enums\AssistanceStatus;
use App\Enums\AssistanceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAssistanceRequest;
use App\Http\Requests\Api\V1\UpdateAssistanceRequest;
use App\Http\Resources\AssistanceResource;
use App\Models\Assistance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Assistance programs V1-A (docs/06-PERMISSIONS.md §50): definition and
 * opening. No delete endpoint; completion/cancellation belong to V1-B.
 */
class AssistanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(AssistanceStatus::class)],
            'type' => ['sometimes', Rule::enum(AssistanceType::class)],
            'category' => ['sometimes', 'string', 'max:50'],
        ]);

        $assistances = Assistance::query()
            ->with(['category', 'creator:id,name', 'opener:id,name'])
            ->withCount('currentNominees')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('assistance_type', $type))
            ->when($filters['category'] ?? null, fn ($q, $code) => $q->whereHas('category', fn ($c) => $c->where('code', $code)))
            ->latest('created_at')
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return AssistanceResource::collection($assistances)->additional([
            // UX hint only; every write is re-authorized by its request.
            'abilities' => ['create' => $request->user()->can('assistance.create')],
        ]);
    }

    public function store(StoreAssistanceRequest $request, CreateAssistanceAction $action): JsonResponse
    {
        $assistance = $action->handle($request->validated(), $request->user()?->id);

        return $this->detail($request, $assistance)->response()->setStatusCode(201);
    }

    public function show(Request $request, Assistance $assistance): AssistanceResource
    {
        return $this->detail($request, $assistance);
    }

    public function update(UpdateAssistanceRequest $request, Assistance $assistance, UpdateAssistanceAction $action): AssistanceResource
    {
        return $this->detail($request, $action->handle($assistance, $request->validated(), $request->user()?->id));
    }

    public function open(Request $request, Assistance $assistance, OpenAssistanceAction $action): AssistanceResource
    {
        return $this->detail($request, $action->handle($assistance, $request->user()?->id));
    }

    private function detail(Request $request, Assistance $assistance): AssistanceResource
    {
        $assistance->load(['category', 'items', 'creator:id,name', 'opener:id,name'])->loadCount('currentNominees');
        $user = $request->user();
        $draft = $assistance->isDraft();
        $open = $assistance->isOpen();

        return (new AssistanceResource($assistance))->additional([
            // UX hints only.
            'abilities' => [
                // DRAFT: full edit; OPEN: description, target and dates only.
                'update' => ($draft || $open) && $user->can('assistance.update'),
                'update_definition' => $draft && $user->can('assistance.update'),
                'open' => $draft && $user->can('assistance.open'),
                'preview' => ($draft || $open) && $user->can('assistance.nominate'),
                'nominate' => $open && $user->can('assistance.nominate'),
            ],
        ]);
    }
}
