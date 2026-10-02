<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ActivateFamilyAccountAction;
use App\Enums\ActivationDenial;
use App\Enums\ActivationError;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Exceptions\ActivationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\ActivationChallengeRequest;
use App\Http\Requests\Api\V1\Family\CompleteActivationRequest;
use App\Http\Requests\Api\V1\Family\StartActivationRequest;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyActivation;
use App\Support\FamilyAuth\ResponseFloor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

    /**
     * The account, then the session — the existing Sanctum first-party
     * session, no token and no "remember me". A request that cannot carry a
     * session is refused BEFORE anything is created: an account is never
     * made for a caller that could not be signed in.
     */
    public function complete(CompleteActivationRequest $request, ActivateFamilyAccountAction $activate): JsonResponse
    {
        if (! $request->hasSession()) {
            AuthSecurityLog::record(AuthSecurityEventType::ACTIVATION_COMPLETED, AuthSecurityEventOutcome::DENIED, ActivationDenial::SESSION_REQUIRED);

            throw new ActivationException(ActivationError::ACTIVATION_FAILED);
        }

        $user = $activate->handle($request->challenge(), (string) $request->input('password'));

        // Committed. One browser session is either Staff or Family: whatever
        // was signed in here is replaced, and the session id is new.
        $guard = Auth::guard('web');
        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
        }
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return FamilySessionController::current($request, 201);
    }

    /** @param  array<string, mixed>  $body */
    private static function json(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
