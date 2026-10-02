<?php

namespace App\Http\Middleware;

use App\Support\AccountSide;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Staff API boundary (docs/06 §22b, AUTH-ADR-065): the Staff API is for
 * Staff-side accounts, and that does not rest on permissions alone. Staff
 * and family-side sessions share one guard, so any account holding a
 * family-side role (FAMILY_USER, COORDINATOR, both, or either one mixed with
 * a Staff role) is refused here — even if it somehow holds the permission
 * the route asks for. Decided from ALL of the account's roles, so role order
 * is irrelevant.
 *
 * Applied once, on the authenticated Staff API route group; never on the
 * future /api/v1/family routes. Legitimate Staff accounts are unaffected:
 * the route's own permission checks still decide what they may do.
 */
class EnsureStaffSideAccount
{
    public const MESSAGE = 'هذا الحساب غير مصرح له باستخدام واجهة الطاقم.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && AccountSide::holdsFamilySideRole($user)) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
