<?php

namespace App\Http\Middleware;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilLookupGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Civil Registry Lookup gate (FB-CIVIL-02A, ADR §L-1): closed → 503
 * LOOKUP_DISABLED before anything else. Runs before the route limiter, so a
 * closed gate counts nothing.
 */
class EnsureCivilLookupEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CivilLookupGate::allows()) {
            throw new CivilLookupException(CivilLookupException::DISABLED);
        }

        return $next($request);
    }
}
