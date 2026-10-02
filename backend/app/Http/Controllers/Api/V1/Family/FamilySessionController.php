<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Exceptions\FamilyAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Family\FamilyLoginRequest;
use App\Http\Resources\FamilyCurrentUserResource;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The Family Portal session (docs/06 §22b): the same Sanctum first-party
 * session as the Staff application — an HttpOnly cookie, no token. One
 * browser session is either Staff or Family.
 */
class FamilySessionController extends Controller
{
    /**
     * National ID + password (FamilyLogin holds every rule). A request that
     * cannot carry a session is refused before any password is checked, so
     * the endpoint is no password oracle for a non-browser caller.
     */
    public function login(FamilyLoginRequest $request, FamilyLogin $login): JsonResponse
    {
        if (! $request->hasSession()) {
            AuthSecurityLog::record(AuthSecurityEventType::LOGIN_FAILED, AuthSecurityEventOutcome::DENIED, LoginDenial::SESSION_REQUIRED);

            throw new FamilyAuthException(FamilyAuthError::INVALID_CREDENTIALS);
        }

        $user = $login->attempt($request->nationalId(), (string) $request->input('password'), $request->ip());

        return self::signIn($request, $user);
    }

    public function me(Request $request): JsonResponse
    {
        return self::current($request);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * Signs $user in on the first-party session and answers with the /me
     * representation. One browser session is either Staff or Family:
     * whatever was signed in here is replaced, and the session id and the
     * CSRF token are new. No token and no "remember me".
     */
    public static function signIn(Request $request, User $user, int $status = 200): JsonResponse
    {
        $guard = Auth::guard('web');
        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
        }
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return self::current($request, $status);
    }

    /** The bootstrap representation; never cached by a browser or a proxy. */
    public static function current(Request $request, int $status = 200): JsonResponse
    {
        return response()
            ->json(['user' => new FamilyCurrentUserResource($request->user())], $status)
            ->header('Cache-Control', 'no-store');
    }
}
