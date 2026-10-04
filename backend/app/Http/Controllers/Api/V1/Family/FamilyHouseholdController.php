<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Resources\FamilyHouseholdResource;
use App\Support\FamilyPortal\HouseholdReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in household head's own household (docs/11 §23, PWA-3A). The
 * Family is the one the family.context boundary resolved; nothing in the
 * request — no route parameter, query string or body — can choose another.
 */
class FamilyHouseholdController extends Controller
{
    public function __construct(private readonly HouseholdReadModel $household) {}

    public function show(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return (new FamilyHouseholdResource($this->household->summary($context), $context->person))
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
