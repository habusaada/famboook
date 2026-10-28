<?php

namespace App\Http\Middleware;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixes the lookup context of a route (FB-CIVIL-02B, ADR 02B §B-1):
 * `civil-lookup.context:FAMILY`. The client never chooses it.
 *
 * Fails closed when the route's side boundary has not run first: a FAMILY
 * route needs the Family context that `family.context` resolved (an eligible
 * household head), a COORDINATOR route the context that `coordinator.space`
 * resolved (an effective assignment). The boundaries stay authoritative; this
 * only refuses a route registered outside them.
 */
class SetCivilLookupContext
{
    public function handle(Request $request, Closure $next, string $context): Response
    {
        $boundary = match ($context) {
            CivilLookupContext::STAFF => true,
            CivilLookupContext::FAMILY => $request->attributes->get(FamilyAccessResult::class) instanceof FamilyAccessResult,
            CivilLookupContext::COORDINATOR => $request->attributes->get(CoordinatorAccessResult::class) instanceof CoordinatorAccessResult,
            default => false,
        };
        if (! $boundary) {
            throw new CivilLookupException(CivilLookupException::NOT_ALLOWED);
        }
        CivilLookupContext::set($request, $context);

        return $next($request);
    }
}
