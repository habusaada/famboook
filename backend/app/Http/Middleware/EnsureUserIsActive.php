<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single enforcement point for deactivated accounts (docs/06 §59c,
 * AUTH-ADR-057). Appended to the web and api middleware groups, so it runs
 * on every Staff API request and every Filament request: a user who was
 * deactivated while holding a session is logged out and denied on their
 * very next request, not only at the next login.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'انتهت الجلسة. الرجاء تسجيل الدخول مجددًا.'], 401);
            }

            return redirect()->guest('/admin/login');
        }

        return $next($request);
    }
}
