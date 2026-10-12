<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\CoordinatorMemberRevealRequest;
use App\Http\Resources\CoordinatorFamilyDetailResource;
use App\Http\Resources\CoordinatorMemberResource;
use App\Support\Coordinator\CoordinatorMemberDirectory;
use App\Support\Coordinator\CoordinatorMemberSensitiveReveal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Coordinator members directory and the one-field reveal (CO-01C, docs/06
 * AUTH-ADR-092). Behind `coordinator.space` (re-resolved on every request),
 * coordinator-family.view-summary and coordinator-member.view; the reveal
 * additionally needs coordinator-member.view-sensitive. The family code and
 * the member reference are plain path strings — never route-model bound —
 * looked up only inside the Coordinator's scoped query. Out of scope and
 * nonexistent are the same 404. Every answer is no-store.
 */
class CoordinatorMemberController extends Controller
{
    public const MEMBER_NOT_FOUND = ['message' => 'الفرد غير متاح.', 'code' => 'MEMBER_NOT_FOUND'];

    private const NO_STORE = 'no-store, private';

    public function __construct(private readonly CoordinatorMemberDirectory $directory) {}

    public function index(Request $request, string $familyCode): JsonResponse
    {
        $context = CoordinatorSpaceController::coordinator($request);
        $family = $this->directory->family($context, $familyCode);

        if ($family === null) {
            return response()->json(CoordinatorFamilyController::NOT_FOUND, 404)->header('Cache-Control', self::NO_STORE);
        }

        return response()->json([
            'data' => [
                'family' => new CoordinatorFamilyDetailResource($family),
                'members' => CoordinatorMemberResource::collection($this->directory->members($family)),
                // UX only: whether the reveal controls are offered. The reveal
                // route re-checks the permission and the scope itself.
                'can_reveal' => (bool) $request->user()?->can('coordinator-member.view-sensitive'),
            ],
        ])->header('Cache-Control', self::NO_STORE);
    }

    public function reveal(CoordinatorMemberRevealRequest $request, string $familyCode, string $memberRef, CoordinatorMemberSensitiveReveal $reveal): JsonResponse
    {
        $field = $request->field();
        $result = $reveal->reveal(CoordinatorSpaceController::coordinator($request), $familyCode, $memberRef, $field);

        if (! $result['found']) {
            return response()->json(self::MEMBER_NOT_FOUND, 404)->header('Cache-Control', self::NO_STORE);
        }

        return response()
            ->json(['data' => ['field' => $field->value, 'value' => $result['value']]])
            ->header('Cache-Control', self::NO_STORE);
    }
}
