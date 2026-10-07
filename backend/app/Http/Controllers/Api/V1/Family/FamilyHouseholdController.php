<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Resources\FamilyHouseholdAssistanceResource;
use App\Http\Resources\FamilyHouseholdHealthResource;
use App\Http\Resources\FamilyHouseholdMemberResource;
use App\Http\Resources\FamilyHouseholdNeedsResource;
use App\Http\Resources\FamilyHouseholdProfileResource;
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
            ->header('Cache-Control', 'no-store, private');
    }

    /** One row per active membership of the resolved Family (PWA-3A Step 3). */
    public function members(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return response()->json(['data' => [
            'family_code' => $context->family->family_code,
            'members' => FamilyHouseholdMemberResource::collection($this->household->members($context)),
        ]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The registered health facts of the household's members (PWA-3B.6),
     * grouped by member_ref. Read-only; no security event.
     */
    public function health(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return (new FamilyHouseholdHealthResource($this->household->health($context)))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The household's registered needs, every status (PWA-3B.7). Read-only;
     * no security event.
     */
    public function needs(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return (new FamilyHouseholdNeedsResource([
            'family_id' => (int) $context->family->getKey(),
            'needs' => $this->household->needs($context),
        ]))->response()->header('Cache-Control', 'no-store, private');
    }

    /**
     * Assistance the household actually received: non-reversed INTERNAL
     * deliveries only (PWA-3B.7). Read-only; no security event.
     */
    public function assistance(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        $deliveries = $this->household->assistance($context);

        return (new FamilyHouseholdAssistanceResource([
            'family_id' => (int) $context->family->getKey(),
            'deliveries' => $deliveries,
            'packages' => $this->household->packages($deliveries),
        ]))->response()->header('Cache-Control', 'no-store, private');
    }

    /** The «أسرتي» profile: family facts and the current residence (PWA-3A Step 4). */
    public function profile(Request $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return (new FamilyHouseholdProfileResource($this->household->profile($context), $context->person))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
