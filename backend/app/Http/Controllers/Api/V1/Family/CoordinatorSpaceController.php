<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coordinator Space (docs/11 §8, §30a): what the Coordinator may consider,
 * resolved by CoordinatorScopes from the context the `coordinator.space`
 * boundary put on the request. Never cached by a browser or a proxy.
 */
class CoordinatorSpaceController extends Controller
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /** The effective scopes (codes and names) and how many Families they cover. */
    public function context(Request $request): JsonResponse
    {
        $context = self::coordinator($request);

        return response()->json(['data' => [
            'scopes' => $this->scopes->summary($context),
            'family_count' => $this->scopes->families($context)->count(),
        ]])->header('Cache-Control', 'no-store');
    }

    /** The context the boundary resolved — the only source of a scope. */
    public static function coordinator(Request $request): CoordinatorAccessResult
    {
        return $request->attributes->get(CoordinatorAccessResult::class);
    }
}
