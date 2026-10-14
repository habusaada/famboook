<?php

namespace App\Support\FamilyAuth;

use App\Enums\CoordinatorAccessDenial;
use App\Enums\CoordinatorScopeType;
use App\Enums\FamilyStatus;
use App\Enums\SubmissionChannel;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\User;
use App\Support\AccountSide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The one authority on Coordinator access (docs/11 §8, §30a; docs/06 §22b).
 *
 * WHO is a Coordinator right now — ALL of:
 *   the account's OWN Family context (FamilyAccessResolver::familyContext:
 *   active family-side account with FAMILY_USER, eligible household head,
 *   ACTIVE Family) · the COORDINATOR role · coordinator-space.access · at
 *   least one EFFECTIVE assignment
 * Role without assignment, assignment without role, a lost household-head
 * eligibility: no Coordinator Space. There is no Coordinator-only fallback.
 *
 * WHICH Families — the UNION of the effective assignments, evaluated against
 * the CURRENT hierarchy on every call (a moved Family or a moved Branch takes
 * effect on the next request):
 *   CLAN          families of that Clan
 *   BRANCH_GROUP  families whose Branch is in that Group
 *   BRANCH        families of that Branch
 * An assignment is effective while it is not revoked and its target is
 * active. Inactive structure FAILS CLOSED: a Clan, Group or Branch that is
 * inactive authorizes nothing, and a Family whose own Branch — or that
 * Branch's Group — is inactive is outside every scope.
 *
 * Families are always selected by a query (EXISTS over the assignments),
 * never by loading ids into PHP. No cache: a revocation is effective at once.
 * Pure reads: no lock and no audit event.
 *
 * This is NOT App\Support\Reporting\OrganizationalScope, which is a
 * reporting filter built from client choices and is never authorization.
 */
final class CoordinatorScopes
{
    public function __construct(private readonly FamilyAccessResolver $resolver) {}

    public function context(User $user): CoordinatorAccessResult
    {
        if (! $this->resolver->familyContext($user)->hasFamilyContext()) {
            return CoordinatorAccessResult::denied(CoordinatorAccessDenial::NO_FAMILY_CONTEXT);
        }
        if (! $user->hasRole(AccountSide::COORDINATOR)) {
            return CoordinatorAccessResult::denied(CoordinatorAccessDenial::NOT_COORDINATOR);
        }
        if (! $user->can('coordinator-space.access')) {
            return CoordinatorAccessResult::denied(CoordinatorAccessDenial::NO_SPACE_PERMISSION);
        }

        $assignments = $this->effectiveAssignments($user);

        return $assignments->isEmpty()
            ? CoordinatorAccessResult::denied(CoordinatorAccessDenial::NO_EFFECTIVE_SCOPE)
            : CoordinatorAccessResult::granted($user, $assignments);
    }

    /**
     * The user's assignments that authorize right now: not revoked, with an
     * active target — the Clan, and the Group or the Branch (and a grouped
     * Branch's Group) for the narrower levels.
     *
     * @return Collection<int, CoordinatorScopeAssignment>
     */
    public function effectiveAssignments(User $user): Collection
    {
        return CoordinatorScopeAssignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->whereHas('clan', fn (Builder $q) => $q->where('is_active', true))
            ->where(fn (Builder $q) => $q
                ->where('scope_type', CoordinatorScopeType::CLAN->value)
                ->orWhere(fn (Builder $q) => $q
                    ->where('scope_type', CoordinatorScopeType::BRANCH_GROUP->value)
                    ->whereHas('branchGroup', fn (Builder $g) => $g->where('is_active', true)))
                ->orWhere(fn (Builder $q) => $q
                    ->where('scope_type', CoordinatorScopeType::BRANCH->value)
                    ->whereHas('branch', fn (Builder $b) => $b
                        ->where('is_active', true)
                        ->where(fn (Builder $b) => $b
                            ->whereNull('branch_group_id')
                            ->orWhereHas('group', fn (Builder $g) => $g->where('is_active', true))))))
            ->with(['clan:id,code,name', 'branchGroup:id,code,name', 'branch:id,code,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The Families this Coordinator may consider — a query, to be narrowed
     * further by the caller (search, filters) but never widened. ACTIVE and
     * not deleted only.
     *
     * @return Builder<Family>
     */
    public function families(CoordinatorAccessResult $context): Builder
    {
        if (! $context->allowed()) {
            // Never reached through the gate; an empty query if it ever is.
            return Family::query()->whereRaw('1 = 0');
        }
        $userId = $context->user->getKey();

        return Family::query()
            ->where('families.status', FamilyStatus::ACTIVE->value)
            // The Family's own Branch, when it has one, is active and — when
            // grouped — in an active Group. Families without a Branch are
            // reachable through a CLAN scope only.
            ->where(fn (Builder $q) => $q
                ->whereNull('families.branch_id')
                ->orWhereExists(fn (QueryBuilder $b) => $b->selectRaw('1')
                    ->from('branches as fb')
                    ->whereColumn('fb.id', 'families.branch_id')
                    ->where('fb.is_active', true)
                    ->where(fn (QueryBuilder $g) => $g
                        ->whereNull('fb.branch_group_id')
                        ->orWhereExists(fn (QueryBuilder $x) => $x->selectRaw('1')
                            ->from('branch_groups as fg')
                            ->whereColumn('fg.id', 'fb.branch_group_id')
                            ->where('fg.is_active', true)))))
            // At least one of the user's active assignments covers it. The
            // assignment's Clan is the Family's Clan for every level (the
            // composite foreign keys keep Groups and Branches in their Clan).
            ->whereExists(fn (QueryBuilder $a) => $a->selectRaw('1')
                ->from('coordinator_scope_assignments as csa')
                ->join('clans as cc', 'cc.id', '=', 'csa.clan_id')
                ->where('csa.user_id', $userId)
                ->whereNull('csa.revoked_at')
                ->where('cc.is_active', true)
                ->whereColumn('csa.clan_id', 'families.clan_id')
                ->where(fn (QueryBuilder $s) => $s
                    ->where('csa.scope_type', CoordinatorScopeType::CLAN->value)
                    ->orWhere(fn (QueryBuilder $x) => $x
                        ->where('csa.scope_type', CoordinatorScopeType::BRANCH->value)
                        ->whereColumn('csa.branch_id', 'families.branch_id'))
                    ->orWhere(fn (QueryBuilder $x) => $x
                        ->where('csa.scope_type', CoordinatorScopeType::BRANCH_GROUP->value)
                        ->whereExists(fn (QueryBuilder $b) => $b->selectRaw('1')
                            ->from('branches as gb')
                            ->whereColumn('gb.id', 'families.branch_id')
                            ->whereColumn('gb.branch_group_id', 'csa.branch_group_id')))));
    }

    public function canAccessFamily(CoordinatorAccessResult $context, Family $family): bool
    {
        return $this->families($context)->whereKey($family->getKey())->exists();
    }

    /**
     * Does the effective scope cover a PROPOSED Family placed in this Clan
     * and, optionally, this Branch (CO-02.3b; FAMILY_ADD, docs/03 amended)?
     * The Clan and Branch are re-read: an inactive Clan, an inactive Branch,
     * a Branch of another Clan or a grouped Branch whose Group is inactive
     * is covered by nothing. Then the union of the effective assignments:
     *
     *   CLAN          its Clan, with or without a Branch
     *   BRANCH_GROUP  a Branch of that Group
     *   BRANCH        that Branch
     *
     * A placement WITHOUT a Branch is covered only by a CLAN assignment.
     */
    public function coversPlacement(CoordinatorAccessResult $context, Clan $clan, ?Branch $branch = null): bool
    {
        if (! $context->allowed()) {
            return false;
        }
        $clan = Clan::query()->whereKey($clan->getKey())->where('is_active', true)->first();
        if ($clan === null) {
            return false;
        }
        if ($branch !== null) {
            $branch = Branch::query()->with('group')
                ->whereKey($branch->getKey())
                ->where('clan_id', $clan->getKey())
                ->where('is_active', true)
                ->first();
            if ($branch === null || ($branch->branch_group_id !== null && $branch->group?->is_active !== true)) {
                return false;
            }
        }

        return $context->assignments->contains(fn (CoordinatorScopeAssignment $assignment) => self::assignmentCovers($assignment, $clan, $branch));
    }

    /**
     * The placements this Coordinator may propose a NEW Family in (CO-02.6a,
     * the FAMILY_ADD form): per Clan, whether a placement WITHOUT a Branch is
     * allowed (a CLAN assignment) and the Branches covered — exactly the
     * placements coversPlacement() accepts, from the same rule. Active
     * structure only (an inactive Clan, Branch or a grouped Branch's inactive
     * Group is never offered); a Clan with nothing to offer is left out.
     * Public codes and names only — never an internal id or an assignment.
     *
     * @return list<array{clan: array{code: string, name: string|null}, branchless_allowed: bool,
     *     branches: list<array{code: string, name: string|null, group: array{code: string, name: string|null}|null}>}>
     */
    public function placementOptions(CoordinatorAccessResult $context): array
    {
        if (! $context->allowed()) {
            return [];
        }
        $clanIds = $context->assignments->pluck('clan_id')->unique()->values()->all();
        $clans = Clan::query()->whereKey($clanIds)->where('is_active', true)->orderBy('name')->orderBy('code')->get();
        $branches = Branch::query()->with('group')
            ->whereIn('clan_id', $clanIds)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Branch $branch) => $branch->branch_group_id === null || $branch->group?->is_active === true);

        $options = [];
        foreach ($clans as $clan) {
            $covers = fn (?Branch $branch) => $context->assignments->contains(
                fn (CoordinatorScopeAssignment $assignment) => self::assignmentCovers($assignment, $clan, $branch));
            $covered = $branches
                ->filter(fn (Branch $branch) => (int) $branch->clan_id === (int) $clan->getKey() && $covers($branch))
                ->sortBy([
                    fn (Branch $a, Branch $b) => ($a->group->sort_order ?? PHP_INT_MAX) <=> ($b->group->sort_order ?? PHP_INT_MAX),
                    fn (Branch $a, Branch $b) => $a->sort_order <=> $b->sort_order,
                    fn (Branch $a, Branch $b) => strcmp($a->code, $b->code),
                ])
                ->map(fn (Branch $branch) => [
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'group' => $branch->group === null ? null : ['code' => $branch->group->code, 'name' => $branch->group->name],
                ])
                ->values()
                ->all();
            $branchless = $covers(null);
            if ($branchless || $covered !== []) {
                $options[] = [
                    'clan' => ['code' => $clan->code, 'name' => $clan->name],
                    'branchless_allowed' => $branchless,
                    'branches' => $covered,
                ];
            }
        }

        return $options;
    }

    /**
     * Does ONE effective assignment cover this placement (the Clan and Branch
     * already known active and consistent)? CLAN: its Clan, with or without a
     * Branch; BRANCH_GROUP: a Branch of that Group; BRANCH: that Branch.
     */
    private static function assignmentCovers(CoordinatorScopeAssignment $assignment, Clan $clan, ?Branch $branch): bool
    {
        return (int) $assignment->clan_id === (int) $clan->getKey()
            && match ($assignment->scope_type) {
                CoordinatorScopeType::CLAN => true,
                CoordinatorScopeType::BRANCH_GROUP => $branch?->branch_group_id !== null
                    && (int) $branch->branch_group_id === (int) $assignment->branch_group_id,
                CoordinatorScopeType::BRANCH => $branch !== null && (int) $branch->getKey() === (int) $assignment->branch_id,
            };
    }

    /**
     * The Change Requests this Coordinator may see and act on (CO-02.3b;
     * docs/06 AUTH-ADR-093) — a query, to be narrowed by the caller but never
     * widened: requests THIS account submitted through the COORDINATOR
     * channel that are still in the CURRENT scope —
     *
     *   about a Family       that Family is in families()
     *   a proposed Family    once APPLIED, the created Family is in
     *                        families(); before, its placement is covered
     *                        (the same rules as coversPlacement())
     *
     * A revoked or narrowed scope removes the requests it no longer covers;
     * nothing is deleted, and the Staff queue is unaffected. Family-channel
     * requests — including the Coordinator's own household's — never appear.
     *
     * @return Builder<ChangeRequest>
     */
    public function requests(CoordinatorAccessResult $context): Builder
    {
        if (! $context->allowed()) {
            return ChangeRequest::query()->whereRaw('1 = 0');
        }
        $userId = $context->user->getKey();

        return ChangeRequest::query()
            ->where('change_requests.submission_channel', SubmissionChannel::COORDINATOR->value)
            ->where('change_requests.submitted_by', $userId)
            ->where(fn (Builder $q) => $q
                ->whereIn('change_requests.family_id', $this->families($context)->select('families.id')->toBase())
                ->orWhereIn('change_requests.created_family_id', $this->families($context)->select('families.id')->toBase())
                ->orWhere(fn (Builder $p) => $this->placementCovered($p->whereNull('change_requests.family_id')
                    ->whereNull('change_requests.created_family_id'), $userId)));
    }

    /**
     * The proposed placement of the outer change_requests row is covered by
     * one of the user's effective assignments (SQL form of coversPlacement()).
     *
     * @param  Builder<ChangeRequest>  $query
     * @return Builder<ChangeRequest>
     */
    private function placementCovered(Builder $query, int $userId): Builder
    {
        return $query
            ->whereExists(fn (QueryBuilder $c) => $c->selectRaw('1')
                ->from('clans as pc')
                ->whereColumn('pc.id', 'change_requests.proposed_clan_id')
                ->where('pc.is_active', true))
            ->where(fn (Builder $b) => $b
                ->whereNull('change_requests.proposed_branch_id')
                ->orWhereExists(fn (QueryBuilder $x) => $x->selectRaw('1')
                    ->from('branches as pb')
                    ->whereColumn('pb.id', 'change_requests.proposed_branch_id')
                    ->whereColumn('pb.clan_id', 'change_requests.proposed_clan_id')
                    ->where('pb.is_active', true)
                    ->where(fn (QueryBuilder $g) => $g
                        ->whereNull('pb.branch_group_id')
                        ->orWhereExists(fn (QueryBuilder $y) => $y->selectRaw('1')
                            ->from('branch_groups as pg')
                            ->whereColumn('pg.id', 'pb.branch_group_id')
                            ->where('pg.is_active', true)))))
            ->whereExists(fn (QueryBuilder $a) => $a->selectRaw('1')
                ->from('coordinator_scope_assignments as csa')
                ->where('csa.user_id', $userId)
                ->whereNull('csa.revoked_at')
                ->whereColumn('csa.clan_id', 'change_requests.proposed_clan_id')
                ->where(fn (QueryBuilder $s) => $s
                    ->where('csa.scope_type', CoordinatorScopeType::CLAN->value)
                    ->orWhere(fn (QueryBuilder $x) => $x
                        ->where('csa.scope_type', CoordinatorScopeType::BRANCH->value)
                        ->whereColumn('csa.branch_id', 'change_requests.proposed_branch_id'))
                    ->orWhere(fn (QueryBuilder $x) => $x
                        ->where('csa.scope_type', CoordinatorScopeType::BRANCH_GROUP->value)
                        ->whereExists(fn (QueryBuilder $b) => $b->selectRaw('1')
                            ->from('branches as gb')
                            ->whereColumn('gb.id', 'change_requests.proposed_branch_id')
                            ->whereColumn('gb.branch_group_id', 'csa.branch_group_id')))));
    }

    /**
     * The effective scopes for display: level, public code and names. No
     * internal id, no actor, no assignment internals.
     *
     * @return list<array{type: string, code: string, name: string|null, clan_name: string|null}>
     */
    public function summary(CoordinatorAccessResult $context): array
    {
        return $context->assignments->map(function (CoordinatorScopeAssignment $assignment) {
            $target = match ($assignment->scope_type) {
                CoordinatorScopeType::CLAN => $assignment->clan,
                CoordinatorScopeType::BRANCH_GROUP => $assignment->branchGroup,
                CoordinatorScopeType::BRANCH => $assignment->branch,
            };

            return [
                'type' => $assignment->scope_type->value,
                'code' => $target->code,
                'name' => $target->name,
                'clan_name' => $assignment->clan->name,
            ];
        })->values()->all();
    }
}
