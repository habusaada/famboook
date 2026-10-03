<?php

namespace App\Support\FamilyAuth;

use App\Enums\CoordinatorAccessDenial;
use App\Enums\CoordinatorScopeType;
use App\Enums\FamilyStatus;
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
