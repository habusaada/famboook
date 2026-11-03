<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache-Control: no-store, private on EVERY answer of the admission route (FB-FAMILY-ADD-01
 * ADD-WP-07C-C), including the 401 and 403 of the shared family boundary.
 *
 * Route-local: it is the first middleware of that one route, so it wraps auth:sanctum,
 * family.side, can:family-portal.access, family.context and the admission gate. Their
 * refusals are rendered into responses inside the route pipeline before they reach it. No
 * other route, and no shared middleware, changes.
 */
class EnsureRegistryAdmissionNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
