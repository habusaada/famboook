<?php

namespace App\Http\Middleware;

use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Family context boundary (docs/11 §4, docs/06 §22b). Runs after
 * `auth:sanctum`, `family.side` and the route permission, on every Family
 * Portal data route. FAILS CLOSED: only an account that
 * FamilyAccessResolver::familyContext() accepts right now passes — the rules
 * live there and only there.
 *
 * The resolved context is put on the request; Family data endpoints read
 * their Family from there and from nowhere else (never from the client). The
 * refusal is one generic answer whatever the reason; the reason is not sent
 * and not recorded (an ordinary gate refusal is not a security event).
 */
class EnsureFamilyContext
{
    public const MESSAGE = 'بيانات الأسرة غير متاحة لهذا الحساب.';

    public const CODE = 'FAMILY_CONTEXT_UNAVAILABLE';

    public function __construct(private readonly FamilyAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = $user === null ? null : $this->resolver->familyContext($user);

        if ($context === null || ! $context->hasFamilyContext()) {
            return response()
                ->json(['message' => self::MESSAGE, 'code' => self::CODE], 403)
                ->header('Cache-Control', 'no-store');
        }

        $request->attributes->set(FamilyAccessResult::class, $context);

        return $next($request);
    }

    /** The context this boundary resolved for the current request. */
    public static function context(Request $request): FamilyAccessResult
    {
        return $request->attributes->get(FamilyAccessResult::class);
    }
}
