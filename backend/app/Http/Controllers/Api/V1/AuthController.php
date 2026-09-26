<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Staff authentication on the Sanctum first-party session (docs/06 §59c,
 * AUTH-ADR-057). No token is ever returned to JavaScript: the session
 * cookie is HttpOnly and the SPA sends the XSRF token for writes.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();
        // New session id after authentication (session fixation).
        $request->session()->regenerate();

        return response()->json(['user' => new CurrentUserResource($request->user())]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new CurrentUserResource($request->user())]);
    }
}
