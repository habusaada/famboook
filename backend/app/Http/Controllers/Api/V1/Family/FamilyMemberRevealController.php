<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\FamilyMemberRevealRequest;
use App\Support\FamilyPortal\HouseholdMemberSensitiveReveal;
use Illuminate\Http\JsonResponse;

/**
 * The household-member sensitive-value reveal (docs/11 §23a, FP-ADR-064,
 * PWA-3B.4). The member reference in the path is a plain string — never
 * route-model bound — resolved only inside the family.context Family by
 * HouseholdMemberSensitiveReveal; any failure is the generic 404.
 */
class FamilyMemberRevealController extends Controller
{
    public function reveal(FamilyMemberRevealRequest $request, string $memberRef, HouseholdMemberSensitiveReveal $reveal): JsonResponse
    {
        $field = $request->field();
        $value = $reveal->reveal(EnsureFamilyContext::context($request), $memberRef, $field);

        return response()
            ->json(['data' => ['field' => $field->value, 'value' => $value]])
            ->header('Cache-Control', 'no-store, private');
    }
}
