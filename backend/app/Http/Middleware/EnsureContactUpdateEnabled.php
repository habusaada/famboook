<?php

namespace App\Http\Middleware;

use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family mobile contact update gate (docs/11 FP-ADR-079): config
 * family_auth.contact_update_enabled, off by default and independent of
 * the other gates. It SENDS SMS to numbers not yet in the registry, so it
 * stays closed until SMS delivery is Production-ready. FAILS CLOSED and runs
 * before the route limiters (middleware priority): while it is off nothing
 * is checked, counted, recorded or sent.
 */
class EnsureContactUpdateEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('family_auth.contact_update_enabled') !== true) {
            return FamilyAuthException::response(FamilyAuthError::CONTACT_UPDATE_UNAVAILABLE);
        }

        return $next($request);
    }
}
