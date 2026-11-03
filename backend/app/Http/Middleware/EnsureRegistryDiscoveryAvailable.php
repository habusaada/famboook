<?php

namespace App\Http\Middleware;

use App\Support\FamilyPortal\FamilyRegistryAdmissionPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Suggested Children discovery gate (FB-FAMILY-ADD-01 ADD-WP-06B-3; ADR §7, §10,
 * §11). Runs AFTER family.context, on the discovery route only. FAILS CLOSED:
 *
 * - the flag must allow this Family (OFF, a missing or invalid mode, or PILOT
 *   without this Family on the allowlist all refuse) AND the account must hold
 *   family-member.admit-from-registry, which no role has. Both refusals are the
 *   same 404 ADMISSION_UNAVAILABLE, so the route does not tell them apart;
 * - then the per-user discovery limits (10 per minute, 60 per hour by default),
 *   counted here rather than by a route throttle, which the middleware priority list
 *   would run before family.context: a closed gate counts nothing.
 *
 * Nothing is read from the registry, and nothing is recorded, before both pass.
 */
class EnsureRegistryDiscoveryAvailable
{
    public const CODE = 'ADMISSION_UNAVAILABLE';

    public const MESSAGE = 'هذه الخدمة غير متاحة.';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public function handle(Request $request, Closure $next): Response
    {
        $context = EnsureFamilyContext::context($request);
        $user = $request->user();
        if ($context === null || ! $context->hasFamilyContext() || $user === null
            || ! FamilyRegistryAdmissionPolicy::allowsFamily($context->family)
            || ! $user->can('family-member.admit-from-registry')) {
            return self::refuse(self::CODE, self::MESSAGE, 404);
        }

        $windows = $this->windows($user->getAuthIdentifier());
        foreach ($windows as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                return self::refuse(self::RATE_LIMITED, 'طلبات كثيرة، حاول لاحقًا.', 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }
        foreach ($windows as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }

    /**
     * The per-user windows: key => [max, decay seconds]. Keys name the account id only, never
     * a National ID, a reference or a request value.
     *
     * @return array<string, array{int, int}>
     */
    protected function windows(int|string $id): array
    {
        return [
            "family-registry-discovery|user|{$id}|minute" => [(int) config('family_registry_admission.limits.discovery_user_minute'), 60],
            "family-registry-discovery|user|{$id}|hour" => [(int) config('family_registry_admission.limits.discovery_user_hour'), 3600],
        ];
    }

    public static function refuse(string $code, string $message, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status)->header('Cache-Control', 'no-store, private');
    }
}
