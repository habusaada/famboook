<?php

namespace App\Http\Middleware;

use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Coordinator Space boundary (docs/06 §22b, docs/11 §8). Runs after
 * `auth:sanctum` and `family.side`, on every /api/v1/family/coordinator
 * route. FAILS CLOSED: only an account that CoordinatorScopes::context()
 * accepts right now passes — own Family context, COORDINATOR,
 * coordinator-space.access and an effective scope assignment.
 *
 * The result is put on the request; coordinator endpoints read their scope
 * from there and from nowhere else (never from the client). The refusal is
 * one generic answer; the internal reason is not sent and not recorded (an
 * ordinary gate refusal is not a security event).
 */
class EnsureCoordinatorSpace
{
    public const MESSAGE = 'مساحة التنسيق غير متاحة لهذا الحساب.';

    public function __construct(private readonly CoordinatorScopes $scopes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = $user === null ? null : $this->scopes->context($user);

        if ($context === null || ! $context->allowed()) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'COORDINATOR_SPACE_UNAVAILABLE'], 403);
        }

        $request->attributes->set(CoordinatorAccessResult::class, $context);

        return $next($request);
    }
}
