<?php

namespace App\Http\Middleware;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\CivilLookup\CivilLookupGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Civil Registry Lookup gate (FB-CIVIL-02A, ADR §L-1): closed → 503
 * LOOKUP_DISABLED before anything else. Runs before the route limiter, so a
 * closed gate counts nothing.
 *
 * FB-CIVIL-02B: `civil-lookup.enabled:FAMILY` — the gate of the mount's
 * context (a family-side context also needs its own gate). The context is a
 * route parameter, not the request attribute: the priority list moves this
 * middleware ahead of the side boundaries, which is harmless for a gate that
 * reads nothing from the request.
 */
class EnsureCivilLookupEnabled
{
    public function handle(Request $request, Closure $next, string $context = CivilLookupContext::STAFF): Response
    {
        if (! in_array($context, CivilLookupContext::ALL, true)) {
            throw new CivilLookupException(CivilLookupException::NOT_ALLOWED);
        }
        if (! CivilLookupGate::allowsContext($context)) {
            throw new CivilLookupException(CivilLookupException::DISABLED);
        }

        return $next($request);
    }
}
