<?php

namespace App\Http\Middleware;

use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family authenticated password change gate (docs/11 FU-16): config
 * family_auth.password_change_enabled, off by default and independent of
 * the activation, login and reset gates. FAILS CLOSED and runs before the
 * route limiter and the controller: while it is off nothing is checked,
 * counted or recorded. Deploying the code never opens it.
 */
class EnsurePasswordChangeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('family_auth.password_change_enabled') !== true) {
            return FamilyAuthException::response(FamilyAuthError::PASSWORD_CHANGE_UNAVAILABLE);
        }

        return $next($request);
    }
}
