<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ActivateFamilyAccountAction;
use App\Enums\ActivationDenial;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\FamilyChallengeRequest;
use App\Http\Requests\Api\V1\Family\FamilyIdentifierRequest;
use App\Http\Requests\Api\V1\Family\FamilyPasswordRequest;
use App\Support\FamilyAuth\AuthSecurityLog;
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
    public function start(FamilyIdentifierRequest $request, FamilyActivation $activation): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($activation->start($request->nationalId()));
        } finally {
            // Whatever happened: a sent SMS, a decoy or a refusal.
            ResponseFloor::hold($startedAt);
        }
    }

    public function verify(FamilyChallengeRequest $request, FamilyActivation $activation): JsonResponse
    {
        return self::json($activation->verify($request->challenge(), (string) $request->code()));
    }

    public function resend(FamilyChallengeRequest $request, FamilyActivation $activation): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($activation->resend($request->challenge()));
        } finally {
            ResponseFloor::hold($startedAt);
        }
    }

    /**
     * The account, then the session — the existing Sanctum first-party
     * session, no token and no "remember me". A request that cannot carry a
     * session is refused BEFORE anything is created: an account is never
     * made for a caller that could not be signed in.
     */
    public function complete(FamilyPasswordRequest $request, ActivateFamilyAccountAction $activate): JsonResponse
    {
        if (! $request->hasSession()) {
            AuthSecurityLog::record(AuthSecurityEventType::ACTIVATION_COMPLETED, AuthSecurityEventOutcome::DENIED, ActivationDenial::SESSION_REQUIRED);

            throw new FamilyAuthException(FamilyAuthError::ACTIVATION_FAILED);
        }

        $user = $activate->handle($request->challenge(), (string) $request->input('password'));

        // Committed: only now the session.
        return FamilySessionController::signIn($request, $user, 201);
    }

    /** @param  array<string, mixed>  $body */
    private static function json(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
