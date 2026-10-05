<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Resources\FamilySelfResource;
use App\Support\FamilyPortal\HouseholdReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «بياناتي الشخصية» (docs/11 §23a, PWA-3B.1): the signed-in household head's
 * own registry data. SELF only — the Person and membership are the ones the
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
}
