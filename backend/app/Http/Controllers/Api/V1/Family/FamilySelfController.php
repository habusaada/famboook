<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\FamilySelfRevealRequest;
use App\Http\Resources\FamilySelfResource;
use App\Support\FamilyPortal\HouseholdReadModel;
use App\Support\FamilyPortal\SelfSensitiveReveal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «بياناتي الشخصية» (docs/11 §23a, PWA-3B.1 / 3B.2): the signed-in household
 * head's own registry data, masked, and the reveal of one own sensitive
 * value. SELF only — the Person and membership are the ones the
 * family.context boundary resolved; nothing in the request (route
 * parameter, query string or body) can choose another.
 */
class FamilySelfController extends Controller
{
    public function __construct(private readonly HouseholdReadModel $household) {}

    public function show(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        // Masked sensitive values: never stored by a shared or browser cache.
        return (new FamilySelfResource($this->household->self($context)))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * One of the head's OWN full sensitive values (PWA-3B.2): only the
     * requested field, from the Family context's Person; recorded as a
     * security event (the field code, never the value).
     */
    public function reveal(FamilySelfRevealRequest $request, SelfSensitiveReveal $reveal): JsonResponse
    {
        $field = $request->field();
        $value = $reveal->reveal(EnsureFamilyContext::context($request), $field);

        return response()
            ->json(['data' => ['field' => $field->value, 'value' => $value]])
            ->header('Cache-Control', 'no-store, private');
    }
}
