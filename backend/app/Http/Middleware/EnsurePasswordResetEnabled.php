<?php

namespace App\Http\Middleware;

use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family password reset gate (docs/08 §16a): config
 * family_auth.password_reset_enabled, off by default and independent of the
 * activation and login gates — a reset sends an SMS, so it stays closed until
 * SMS delivery is Production-ready. FAILS CLOSED and runs first on the four
 * reset routes: while it is off there is no lookup, no security event, no
 * decoy, no OTP and no SMS. Deploying the code never opens it.
 */
class EnsurePasswordResetEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('family_auth.password_reset_enabled') !== true) {
            return FamilyAuthException::response(FamilyAuthError::PASSWORD_RESET_UNAVAILABLE);
        }

        return $next($request);
    }
}
