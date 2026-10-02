<?php

namespace App\Http\Middleware;

use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family login gate (docs/08 §16a): config family_auth.login_enabled,
 * off by default and independent of the activation and password reset
 * gates. FAILS CLOSED and runs first on the login route — while it is off
 * there is no lookup, no password check and no security event. Deploying the
 * code never opens it. /family/me and logout are not gated.
 */
class EnsureFamilyLoginEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('family_auth.login_enabled') !== true) {
            return FamilyAuthException::response(FamilyAuthError::FAMILY_AUTH_UNAVAILABLE);
        }

        return $next($request);
    }
}
