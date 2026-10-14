<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\FamilyChangeRequestIndexRequest;
use App\Http\Requests\Api\V1\Family\ResubmitFamilyChangeRequestRequest;
use App\Http\Requests\Api\V1\Family\SubmitCoordinatorChangeRequestRequest;
use App\Http\Requests\Api\V1\Family\SubmitCoordinatorProposalRequest;
use App\Http\Resources\ChangeRequestOutcomeResource;
use App\Http\Resources\CoordinatorChangeRequestResource;
use App\Http\Resources\CoordinatorChangeRequestSummaryResource;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\CoordinatorSubmissionPolicy;
use App\Support\ChangeRequests\CoordinatorSubmittable;
use App\Support\ChangeRequests\ProposesNewFamily;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\RelationshipTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Coordinator's own Change Requests (CO-02.4; docs/06 AUTH-ADR-093,
 * docs/adr/CO-02-COORDINATOR-REQUESTS.md §10). Behind auth:sanctum,
 * family.side and coordinator.space; each route also needs its
 * coordinator-request.* permission and a per-user throttle.
 *
 * - Every read starts from CoordinatorScopes::requests() with the context
 *   the coordinator.space boundary resolved: the Coordinator's OWN
 *   COORDINATOR-channel requests still in the CURRENT scope. Another
 *   Coordinator's request, a family-channel request, a request out of scope
 *   and a nonexistent one are the same 404. No route-model binding.
 * - A submission's Family is the path's family_code looked up ONLY inside
 *   CoordinatorScopes::families(); out of scope = 404.
 * - A proposal of a NEW Family (CO-02.5) names its placement by public
 *   Clan / Branch codes; a code that does not exist, an inactive one, a
 *   Branch of another Clan and a placement outside the scope all get the
 *   SAME 422 on `placement` — nothing about an inaccessible Clan or Branch
 *   is disclosed.
 * - Mutations are the CO-02.3b Domain Actions, which re-check the actor,
 *   the permission, the switch, ownership and scope themselves; nothing here
 *   changes a status or the registry. Every response is no-store, private.
 */
class CoordinatorChangeRequestController extends Controller
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /**
     * The types this Coordinator may submit NOW: ADD_FAMILY_MEMBER (about a
     * Family in scope) and REGISTER_FAMILY (a new Family placed in scope),
     * each while registered with the matching handler, the coordinator
     * switch is open FOR THIS ACCOUNT and it holds coordinator-request.submit.
     * `meta.submission_enabled` is this account's switch state only.
     */
    public function types(Request $request, ChangeRequestTypes $types): JsonResponse
    {
        $enabled = CoordinatorSubmissionPolicy::allows($request->user());
        $offered = [
            ...array_map(fn (ChangeRequestType $type) => [$type, CoordinatorSubmittable::class], SubmitCoordinatorChangeRequestRequest::TYPES),
            ...array_map(fn (ChangeRequestType $type) => [$type, ProposesNewFamily::class], SubmitCoordinatorProposalRequest::TYPES),
        ];
        $available = [];
        if ($enabled && $request->user()->can('coordinator-request.submit')) {
            foreach ($offered as [$type, $contract]) {
                if ($types->has($type) && $types->handler($type) instanceof $contract) {
                    $available[] = $type;
                }
            }
        }

        return response()->json([
            'data' => array_map(fn (ChangeRequestType $type) => ['type' => $type->value], $available),
            'meta' => ['submission_enabled' => $enabled],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The relationship options of the PERSON_ADD form (CO-02.6a): the shared
     * definition (RelationshipTypes::memberOptions — active, never HEAD), by
     * stable code and Arabic name.
     */
    public function relationshipTypes(): JsonResponse
    {
        return response()->json(['data' => RelationshipTypes::memberOptions()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The placements of the FAMILY_ADD form (CO-02.6a): only the active
     * Clans / Branches this Coordinator's CURRENT scope covers, and per Clan
     * whether a placement without a Branch is allowed — the same rule the
     * submission enforces (CoordinatorScopes). Codes and names only.
     */
    public function placements(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scopes->placementOptions(CoordinatorSpaceController::coordinator($request))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(FamilyChangeRequestIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $requests = $this->coordinatorRequests($request)
            ->with(CoordinatorChangeRequestSummaryResource::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('change_requests.status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('change_requests.type', $type))
            ->orderByDesc('change_requests.submitted_at')
            ->orderByDesc('change_requests.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return CoordinatorChangeRequestSummaryResource::collection($requests)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $changeRequest): JsonResponse
    {
        $found = $this->find($request, $changeRequest)->load(CoordinatorChangeRequestResource::RELATIONS);

        return (new CoordinatorChangeRequestResource($found))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(SubmitCoordinatorChangeRequestRequest $request, string $familyCode, SubmitChangeRequestAction $action): JsonResponse
    {
        // Only inside the authorized query: out of scope = does not exist.
        $family = $this->scopes->families(CoordinatorSpaceController::coordinator($request))
            ->where('families.family_code', $familyCode)
            ->first()
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);

        $outcome = $action->handleCoordinator($request->user(), $family, new ChangeRequestSubmission(
            ChangeRequestType::from($request->validated('type')),
            $request->validated('data'),
            $request->validated('reason'),
            $request->validated('client_reference'),
        ));

        return $this->outcome($outcome, $outcome->replayed ? 200 : 201);
    }

    /**
     * A proposal of a NEW Family (CO-02.5): the placement is resolved by its
     * public codes, and SubmitChangeRequestAction::handleCoordinatorProposal
     * checks it against the current scope; every refusal is one answer.
     */
    public function storeProposal(SubmitCoordinatorProposalRequest $request, SubmitChangeRequestAction $action): JsonResponse
    {
        $clan = Clan::query()->where('code', $request->validated('placement.clan_code'))->first()
            ?? throw SubmitChangeRequestAction::placementUnavailable();
        $branchCode = $request->validated('placement.branch_code');
        $branch = null;
        if ($branchCode !== null && $branchCode !== '') {
            $branch = Branch::query()->where('clan_id', $clan->getKey())->where('code', $branchCode)->first()
                ?? throw SubmitChangeRequestAction::placementUnavailable();
        }

        $outcome = $action->handleCoordinatorProposal($request->user(), $clan, $branch, new ChangeRequestSubmission(
            ChangeRequestType::from($request->validated('type')),
            $request->validated('data'),
            $request->validated('reason'),
            $request->validated('client_reference'),
        ));

        return $this->outcome($outcome, $outcome->replayed ? 200 : 201);
    }

    public function resubmit(ResubmitFamilyChangeRequestRequest $request, string $changeRequest, ResubmitChangeRequestAction $action): JsonResponse
    {
        return $this->outcome($action->handleCoordinator($request->user(), $this->find($request, $changeRequest), $request->validated('response')));
    }

    public function cancel(Request $request, string $changeRequest, CancelChangeRequestAction $action): JsonResponse
    {
        return $this->outcome($action->handleCoordinator($request->user(), $this->find($request, $changeRequest)));
    }

    /** @return Builder<ChangeRequest> the Coordinator's own requests in the current scope */
    private function coordinatorRequests(Request $request): Builder
    {
        return $this->scopes->requests(CoordinatorSpaceController::coordinator($request));
    }

    /** One of the Coordinator's own requests in scope, by uuid; anything else is not found. */
    private function find(Request $request, string $uuid): ChangeRequest
    {
        $found = Str::isUuid($uuid) ? $this->coordinatorRequests($request)->where('change_requests.uuid', $uuid)->first() : null;

        return $found ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
    }

    private function outcome(ChangeRequestOutcome $outcome, int $status = 200): JsonResponse
    {
        return (new ChangeRequestOutcomeResource($outcome))
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store, private');
    }
}
