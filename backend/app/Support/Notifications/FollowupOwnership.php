<?php

namespace App\Support\Notifications;

use App\Enums\CoordinatorScopeType;
use App\Enums\FamilyStatus;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The ONE answer to "who owns this family's notification follow-ups?" (ADR
 * FB-NOTIFY-01 ADR-06 §2c, FB-NOTIFY-01E.3-F1). Ownership is DYNAMIC: derived
 * on every call from the current assignments, roles, permissions and
 * hierarchy — never stored on the follow-up, never cached. A revocation, a
 * role removal or a moved Family takes effect on the next call.
 *
 * PRIMARY Coordinator — exactly one per Family, or none:
 *   1. candidates: the users of the NON-REVOKED assignments that cover the
 *      Family (same Clan; CLAN, the BRANCH_GROUP of its Branch, or its
 *      BRANCH), each user ranked by their most specific covering assignment:
 *        BRANCH > BRANCH_GROUP > CLAN, then the earliest assigned_at, then
 *        the lowest assignment id (deterministic, also on equal timestamps);
 *   2. in that order, the FIRST candidate who passes ALL of:
 *        CoordinatorScopes::context() allowed (household head, COORDINATOR
 *        role, coordinator-space.access, an effective assignment) ·
 *        CoordinatorScopes::canAccessFamily() (the very same scope rule —
 *        nothing is duplicated here) · coordinator-notification-followup.manage
 *        · the Family is NOT the candidate's own household.
 *      An ineligible candidate is skipped, never "inherited".
 * No eligible candidate, or a Family that is not ACTIVE: CENTRAL — SUPER_ADMIN
 * and ADMINISTRATOR (notification-followup.*), who oversee and manage every
 * follow-up in any case.
 *
 * The SQL ranking is only an ordering of possible candidates; eligibility is
 * always CoordinatorScopes'. (Every assignment covering one Family targets
 * the Family's own Clan, Group or Branch, so the structure's activity is the
 * same for all of them: the best-ranked covering assignment of an eligible
 * candidate is effective.)
 *
 * VIEWING is separate: any Coordinator whose current scope covers the Family
 * and who holds coordinator-notification-followup.view may see its
 * follow-ups — their own household included. Only the primary manages.
 *
 * Cost: ONE Family per call — one ranking query, then a few queries per
 * candidate examined, stopping at the first eligible one. Not a list
 * resolver: a future list endpoint must paginate with a bound and must not
 * resolve ownership per row without one (no unbounded N+1). Pure reads: no
 * lock and no write. A caller that must act consistently with the answer
 * (record a contact, close manually) locks according to the follow-up lock
 * order first; role / assignment revocation races are that action's to
 * handle, with PostgreSQL concurrency tests.
 */
final class FollowupOwnership
{
    public function __construct(
        private readonly CoordinatorScopes $scopes,
        private readonly FamilyAccessResolver $resolver,
    ) {}

    public function resolve(Family $family): FollowupOwner
    {
        if (! $this->isActive($family)) {
            return FollowupOwner::central();
        }

        $candidates = $this->candidateUserIds($family);
        if ($candidates === []) {
            return FollowupOwner::central();
        }
        $users = User::query()->whereKey($candidates)->get()->keyBy(fn (User $user) => (int) $user->getKey());

        foreach ($candidates as $userId) {
            $user = $users->get($userId);
            if ($user !== null && $this->eligibleOwner($user, $family)) {
                return FollowupOwner::coordinator($user);
            }
        }

        return FollowupOwner::central();
    }

    /** Is this user the Family's primary Coordinator right now? */
    public function isPrimary(User $user, Family $family): bool
    {
        return $this->resolve($family)->isCoordinator($user);
    }

    /**
     * May this Coordinator SEE the Family's follow-ups? Any Coordinator whose
     * CURRENT scope covers the Family and who holds
     * coordinator-notification-followup.view — their own household included
     * when it is in that scope. Viewing never implies managing.
     */
    public function coordinatorMayView(User $user, Family $family): bool
    {
        if (! $user->can('coordinator-notification-followup.view')) {
            return false;
        }
        $context = $this->scopes->context($user);

        return $context->allowed() && $this->scopes->canAccessFamily($context, $family);
    }

    /**
     * May this Coordinator record a contact or close manually? ONLY the
     * primary Coordinator — other in-scope Coordinators view only, and nobody
     * is ever primary for their own household. The future contact and
     * closure Domain Actions must call this (under their locks): the UI is
     * never the enforcement point.
     */
    public function coordinatorMayManage(User $user, Family $family): bool
    {
        return $user->can('coordinator-notification-followup.manage') && $this->isPrimary($user, $family);
    }

    /**
     * The ranked candidate user ids (best first, each user once) — the users
     * of the non-revoked assignments covering the Family, BEFORE eligibility.
     * Ordering only: never authorization on its own.
     *
     * @return list<int>
     */
    public function candidateUserIds(Family $family): array
    {
        $specificity = sprintf(
            "CASE csa.scope_type WHEN '%s' THEN 3 WHEN '%s' THEN 2 ELSE 1 END",
            CoordinatorScopeType::BRANCH->value,
            CoordinatorScopeType::BRANCH_GROUP->value,
        );

        $rows = DB::table('coordinator_scope_assignments as csa')
            ->join('families as f', 'f.clan_id', '=', 'csa.clan_id')
            ->where('f.id', $family->getKey())
            ->whereNull('csa.revoked_at')
            ->where(fn (QueryBuilder $s) => $s
                ->where('csa.scope_type', CoordinatorScopeType::CLAN->value)
                ->orWhere(fn (QueryBuilder $x) => $x
                    ->where('csa.scope_type', CoordinatorScopeType::BRANCH->value)
                    ->whereColumn('csa.branch_id', 'f.branch_id'))
                ->orWhere(fn (QueryBuilder $x) => $x
                    ->where('csa.scope_type', CoordinatorScopeType::BRANCH_GROUP->value)
                    ->whereExists(fn (QueryBuilder $b) => $b->selectRaw('1')
                        ->from('branches as gb')
                        ->whereColumn('gb.id', 'f.branch_id')
                        ->whereColumn('gb.branch_group_id', 'csa.branch_group_id'))))
            ->orderByRaw("{$specificity} DESC")
            ->orderBy('csa.assigned_at')
            ->orderBy('csa.id')
            ->pluck('csa.user_id');

        return $rows->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function eligibleOwner(User $user, Family $family): bool
    {
        if (! $user->can('coordinator-notification-followup.manage') || $this->isOwnFamily($user, $family)) {
            return false;
        }
        $context = $this->scopes->context($user);

        return $context->allowed() && $this->scopes->canAccessFamily($context, $family);
    }

    /** A Coordinator never owns or manages follow-ups of their own household (viewing in scope is allowed). */
    private function isOwnFamily(User $user, Family $family): bool
    {
        $context = $this->resolver->familyContext($user);

        return $context->hasFamilyContext() && (int) $context->family->getKey() === (int) $family->getKey();
    }

    /** The CURRENT status, re-read: a stale model never keeps a Coordinator owner. */
    private function isActive(Family $family): bool
    {
        return Family::query()->whereKey($family->getKey())->where('status', FamilyStatus::ACTIVE->value)->exists();
    }
}
