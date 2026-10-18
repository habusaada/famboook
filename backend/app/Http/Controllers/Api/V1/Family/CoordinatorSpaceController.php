<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Enums\ChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\FamilyMembership;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coordinator Space (docs/11 §8, §30a): what the Coordinator may consider,
 * resolved by CoordinatorScopes from the context the `coordinator.space`
 * boundary put on the request. Never cached by a browser or a proxy.
 */
class CoordinatorSpaceController extends Controller
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /** The effective scopes (codes and names) and how many Families they cover. */
    public function context(Request $request): JsonResponse
    {
        $context = self::coordinator($request);

        return response()->json(['data' => [
            'scopes' => $this->scopes->summary($context),
            'family_count' => $this->scopes->families($context)->count(),
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * FB-FAMILY-DASHBOARD-01: scope-wide counts for the Family home, all
     * database aggregates over the SAME authorized queries as the
     * Coordinator Space lists (CoordinatorScopes) — three queries whatever
     * the scope's size:
     *
     * - family_count: the Families in scope (distinct by construction);
     * - person_count: ACTIVE memberships of those Families — the
     *   registered-member definition (registered_member_count,
     *   active_member_count);
     * - requests: the Coordinator's OWN requests in scope by status group —
     *   in_progress = open statuses (SUBMITTED, UNDER_REVIEW,
     *   RETURNED_FOR_CLARIFICATION, RESUBMITTED, APPROVED); completed =
     *   APPLIED only; closed = REJECTED, CANCELLED.
     */
    public function summary(Request $request): JsonResponse
    {
        $context = self::coordinator($request);
        $families = $this->scopes->families($context);

        $byStatus = $this->scopes->requests($context)
            ->toBase()
            ->selectRaw('change_requests.status as status, count(*) as total')
            ->groupBy('change_requests.status')
            ->pluck('total', 'status');
        $count = fn (array $statuses): int => (int) collect($statuses)->sum(fn (ChangeRequestStatus $s) => (int) ($byStatus[$s->value] ?? 0));

        return response()->json(['data' => [
            'family_count' => (clone $families)->count(),
            'person_count' => FamilyMembership::query()
                ->where('is_active', true)
                ->whereIn('family_id', (clone $families)->select('families.id'))
                ->count(),
            'requests' => [
                'in_progress' => $count(ChangeRequestStatus::open()),
                'completed' => $count([ChangeRequestStatus::APPLIED]),
                'closed' => $count([ChangeRequestStatus::REJECTED, ChangeRequestStatus::CANCELLED]),
            ],
        ]])->header('Cache-Control', 'no-store, private');
    }

    /** The context the boundary resolved — the only source of a scope. */
    public static function coordinator(Request $request): CoordinatorAccessResult
    {
        return $request->attributes->get(CoordinatorAccessResult::class);
    }
}
