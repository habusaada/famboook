<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AssignCoordinatorScopeAction;
use App\Actions\GrantCoordinatorRoleAction;
use App\Actions\RevokeCoordinatorRoleAction;
use App\Actions\RevokeCoordinatorScopeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignCoordinatorScopeRequest;
use App\Http\Requests\Api\V1\RevokeCoordinatorRequest;
use App\Http\Resources\CoordinatorAssignmentResource;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Support\AccountSide;
use App\Support\FamilyAuth\Coordinators;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff API for coordinators (docs/06 §22b): the coordinator state of a
 * Person's family-side account, granting and revoking the role, assigning
 * and revoking scopes. Staff-side only (`staff.side`) and permission-gated
 * per route (coordinator-scope.view / .manage); the Domain Actions re-check
 * both. A family-side account — a Coordinator included — never reaches it.
 * No UI in PWA-1H.
 */
class PersonCoordinatorController extends Controller
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    public function show(Person $person): JsonResponse
    {
        return $this->state($person);
    }

    public function grant(Request $request, Person $person, GrantCoordinatorRoleAction $action): JsonResponse
    {
        $action->handle($request->user(), $person);

        return $this->state($person, 201);
    }

    public function revoke(RevokeCoordinatorRequest $request, Person $person, RevokeCoordinatorRoleAction $action): JsonResponse
    {
        $action->handle($request->user(), $person, $request->reason());

        return $this->state($person);
    }

    public function assignScope(AssignCoordinatorScopeRequest $request, Person $person, AssignCoordinatorScopeAction $action): JsonResponse
    {
        $action->handle($request->user(), $person, $request->target());

        return $this->state($person, 201);
    }

    public function revokeScope(RevokeCoordinatorRequest $request, CoordinatorScopeAssignment $coordinatorScope, RevokeCoordinatorScopeAction $action): JsonResponse
    {
        $assignment = $action->handle($request->user(), $coordinatorScope, $request->reason());

        return response()->json(['data' => new CoordinatorAssignmentResource($assignment->load(['clan', 'branchGroup', 'branch', 'assigner:id,name', 'revoker:id,name']))]);
    }

    private function state(Person $person, int $status = 200): JsonResponse
    {
        $user = Coordinators::accountOf($person);
        $history = $user === null ? collect() : CoordinatorScopeAssignment::query()
            ->where('user_id', $user->getKey())
            ->with(['clan', 'branchGroup', 'branch', 'assigner:id,name', 'revoker:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => [
            'person_code' => $person->person_code,
            'has_family_account' => $user !== null,
            'coordinator' => $user?->fresh()->hasRole(AccountSide::COORDINATOR) ?? false,
            // The same check Coordinator Space itself runs.
            'coordinator_space' => $user !== null && $this->scopes->context($user->fresh())->allowed(),
            'assignments' => CoordinatorAssignmentResource::collection($history),
        ]], $status);
    }
}
