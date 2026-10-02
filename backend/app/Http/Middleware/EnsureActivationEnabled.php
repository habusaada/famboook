<?php

namespace App\Http\Middleware;

use App\Enums\ActivationError;
use App\Exceptions\ActivationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family activation gate (docs/08 §16a): config
 * family_auth.activation_enabled, off by default. FAILS CLOSED and runs
 * before anything else on the activation routes — while it is off there is
 * no lookup, no security event, no OTP and no SMS. Deploying the code never
 * opens it.
 */
class EnsureActivationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('family_auth.activation_enabled') !== true) {
            return ActivationException::response(ActivationError::ACTIVATION_UNAVAILABLE);
        }

        return $next($request);
    }
}
