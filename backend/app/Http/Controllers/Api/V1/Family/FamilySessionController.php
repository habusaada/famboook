<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Resources\FamilyCurrentUserResource;
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

    /** The bootstrap representation; never cached by a browser or a proxy. */
    public static function current(Request $request, int $status = 200): JsonResponse
    {
        return response()
            ->json(['user' => new FamilyCurrentUserResource($request->user())], $status)
            ->header('Cache-Control', 'no-store');
    }
}
