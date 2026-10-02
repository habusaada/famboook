<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\ActivationChallengeRequest;
use App\Http\Requests\Api\V1\Family\StartActivationRequest;
use App\Support\FamilyAuth\FamilyActivation;
use App\Support\FamilyAuth\ResponseFloor;
use Illuminate\Http\JsonResponse;

/**
 * Family account activation (docs/11 §30a): public, behind the activation
 * gate and the route limiters. No rule lives here — see FamilyActivation and
 * ActivateFamilyAccountAction. Responses are generic and never cached.
 */
class FamilyActivationController extends Controller
{
    public function start(StartActivationRequest $request, FamilyActivation $activation): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($activation->start($request->nationalId()));
        } finally {
            // Whatever happened: a sent SMS, a decoy or a refusal.
            ResponseFloor::hold($startedAt);
        }
    }

    public function verify(ActivationChallengeRequest $request, FamilyActivation $activation): JsonResponse
    {
        return self::json($activation->verify($request->challenge(), (string) $request->code()));
    }

    public function resend(ActivationChallengeRequest $request, FamilyActivation $activation): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($activation->resend($request->challenge()));
        } finally {
            ResponseFloor::hold($startedAt);
        }
    }

    /** @param  array<string, mixed>  $body */
    private static function json(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
