<?php

namespace App\Http\Middleware;

use App\Support\AccountSide;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Staff API boundary (docs/06 §22b, AUTH-ADR-065/066). FAILS CLOSED:
 * the Staff API requires AccountSide::STAFF, and that does not rest on
 * permissions alone. Access needs an authenticated user, a Staff-side
 * account, and then the route's own permission.
 *
 * Refused here, even when the account somehow holds the permission the
 * route asks for:
 *
 *   FAMILY   FAMILY_USER, with or without COORDINATOR
 *   INVALID  a Staff role mixed with a family-side role, or COORDINATOR alone
 *   NONE     no role, or only an unrecognised / custom role
 *
 * It is not enough to be "not family-side": Staff login already requires a
 * Staff-side account, and this enforces the same invariant independently.
 * Decided from ALL of the account's roles, so role order is irrelevant.
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

        if ($user !== null && ! AccountSide::isStaff($user)) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
