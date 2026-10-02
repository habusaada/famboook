<?php

namespace App\Http\Middleware;

use App\Support\AccountSide;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family API boundary (docs/06 §22b), the mirror of EnsureStaffSideAccount.
 * FAILS CLOSED: the Family API requires AccountSide::FAMILY — FAMILY_USER,
 * with or without COORDINATOR. Refused here:
 *
 *   STAFF    any Staff account
 *   INVALID  a Staff role mixed with a family-side role, or COORDINATOR alone
 *   NONE     no role, or only an unrecognised / custom role
 *
 * It answers ONE question: is this a family-side account. It does not resolve
 * a Family: holding the account authorizes no family data. Family context is
 * resolved separately (FamilyAccessResolver), on every request that needs it.
 * A deactivated account never gets here (EnsureUserIsActive, 401).
 */
class EnsureFamilySideAccount
{
    public const MESSAGE = 'هذا الحساب غير مصرح له باستخدام بوابة الأسرة.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! AccountSide::isFamily($user)) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
