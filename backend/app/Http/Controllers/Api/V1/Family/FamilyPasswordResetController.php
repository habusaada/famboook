<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ResetFamilyPasswordAction;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Exceptions\FamilyAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\FamilyChallengeRequest;
use App\Http\Requests\Api\V1\Family\FamilyIdentifierRequest;
use App\Http\Requests\Api\V1\Family\FamilyPasswordRequest;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyPasswordReset;
use App\Support\FamilyAuth\ResponseFloor;
use Illuminate\Http\JsonResponse;

/**
 * Family password reset (docs/11 §30a): public, behind the password reset
 * gate and the route limiters. No rule lives here — see FamilyPasswordReset
 * and ResetFamilyPasswordAction. Responses are generic and never cached.
 */
class FamilyPasswordResetController extends Controller
{
    public function start(FamilyIdentifierRequest $request, FamilyPasswordReset $reset): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($reset->start($request->nationalId()));
        } finally {
            // Whatever happened: a sent SMS, a decoy or a refusal.
            ResponseFloor::hold($startedAt);
        }
    }

    public function verify(FamilyChallengeRequest $request, FamilyPasswordReset $reset): JsonResponse
    {
        return self::json($reset->verify($request->challenge(), (string) $request->code()));
    }

    public function resend(FamilyChallengeRequest $request, FamilyPasswordReset $reset): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            return self::json($reset->resend($request->challenge()));
        } finally {
            ResponseFloor::hold($startedAt);
        }
    }

    /**
     * The new password, then the session. A request that cannot carry a
     * session is refused BEFORE anything changes. The reset ends every
     * earlier session of the account inside its transaction; this browser's
     * session is established only after the commit, so it is a new one and
     * is not among those ended.
     */
    public function complete(FamilyPasswordRequest $request, ResetFamilyPasswordAction $reset): JsonResponse
    {
        if (! $request->hasSession()) {
            AuthSecurityLog::record(AuthSecurityEventType::PASSWORD_RESET_COMPLETED, AuthSecurityEventOutcome::DENIED, LoginDenial::SESSION_REQUIRED);

            throw new FamilyAuthException(FamilyAuthError::RESET_FAILED);
        }

        $user = $reset->handle($request->challenge(), (string) $request->input('password'));

        return FamilySessionController::signIn($request, $user);
    }

    /** @param  array<string, mixed>  $body */
    private static function json(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
