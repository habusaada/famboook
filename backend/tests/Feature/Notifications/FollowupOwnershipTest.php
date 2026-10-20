<?php

namespace Tests\Feature\Notifications;

use App\Enums\FamilyStatus;
use App\Enums\NotificationFollowupCloseReason;
use App\Enums\NotificationFollowupContactOutcome;
use App\Enums\NotificationFollowupOutcome;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\Notifications\FollowupOwnership;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F1: the dynamic primary owner of a family's notification
 * follow-ups (ADR FB-NOTIFY-01 ADR-06 §2c) — one primary Coordinator chosen
 * BRANCH > BRANCH_GROUP > CLAN, then the earliest assigned_at, then the lowest
 * assignment id; ineligible candidates skipped; never the Coordinator's own
 * household; CENTRAL otherwise. Nothing stored, nothing cached. Synthetic
 * data only.
 */
class FollowupOwnershipTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private FollowupOwnership $ownership;

    private Clan $clan;

    private BranchGroup $group;

    private Branch $branch;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->ownership = app(FollowupOwnership::class);

        $this->clan = $this->clan();
        $this->group = $this->group($this->clan);
        $this->branch = $this->branch($this->clan, $this->group);
        $this->family = $this->familyIn($this->clan, $this->branch);
    }

    private function assignAt(User $user, Clan|BranchGroup|Branch $target, string $assignedAt): CoordinatorScopeAssignment
    {
        $assignment = $this->assign($user, $target);
        DB::table('coordinator_scope_assignments')->where('id', $assignment->id)->update(['assigned_at' => $assignedAt]);

        return $assignment->fresh();
    }

    private function owner(?Family $family = null): ?int
    {
        $owner = $this->ownership->resolve(($family ?? $this->family)->fresh());

        return $owner->isCentral() ? null : (int) $owner->coordinator->id;
    }

    private function ownFamily(User $user): Family
    {
        return app(FamilyAccessResolver::class)->familyContext($user->fresh())->family;
    }

    private function forgetPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------------ precedence

    public function test_no_covering_assignment_means_central(): void
    {
        $this->assertNull($this->owner());
        $this->assertSame([], $this->ownership->candidateUserIds($this->family));

        // Another Clan's, another Branch's or another Group's coordinator covers nothing here.
        $otherClan = $this->clan('OTHER_CLAN');
        $this->assign($this->coordinator('111111110'), $otherClan);
        $this->assign($this->coordinator('211111110'), $this->branch($this->clan, $this->group));
        $this->assign($this->coordinator('222222220'), $this->group($this->clan));
        $this->assertNull($this->owner());
    }

    public function test_branch_beats_group_beats_clan_whatever_the_assignment_dates(): void
    {
        $clanLevel = $this->coordinator('111111110');
        $groupLevel = $this->coordinator('211111110');
        $branchLevel = $this->coordinator('222222220');
        $this->assignAt($clanLevel, $this->clan, '2026-01-01 00:00:00');
        $this->assignAt($groupLevel, $this->group, '2026-02-01 00:00:00');
        $branchAssignment = $this->assignAt($branchLevel, $this->branch, '2026-03-01 00:00:00');

        $this->assertSame([$branchLevel->id, $groupLevel->id, $clanLevel->id], $this->ownership->candidateUserIds($this->family));
        $this->assertSame($branchLevel->id, $this->owner());

        $branchAssignment->forceFill(['revoked_at' => now(), 'revoked_by' => User::factory()->create()->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertSame($groupLevel->id, $this->owner(), 'a revocation takes effect on the next call');

        CoordinatorScopeAssignment::query()->where('user_id', $groupLevel->id)
            ->update(['revoked_at' => now(), 'revoked_by' => User::factory()->create()->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assertSame($clanLevel->id, $this->owner());

        CoordinatorScopeAssignment::query()->where('user_id', $clanLevel->id)
            ->update(['revoked_at' => now(), 'revoked_by' => User::factory()->create()->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assertNull($this->owner(), 'no eligible coordinator left: CENTRAL');
    }

    public function test_ties_go_to_the_earliest_assignment_then_the_lowest_assignment_id(): void
    {
        $first = $this->coordinator('111111110');
        $second = $this->coordinator('211111110');
        $third = $this->coordinator('222222220');
        // Created first, assigned last.
        $this->assignAt($first, $this->branch, '2026-05-01 00:00:00');
        $this->assignAt($second, $this->branch, '2026-04-01 00:00:00');
        $this->assignAt($third, $this->branch, '2026-04-01 00:00:00');

        // second and third share assigned_at: the lower assignment id (second's) wins.
        $this->assertSame([$second->id, $third->id, $first->id], $this->ownership->candidateUserIds($this->family));
        $this->assertSame($second->id, $this->owner());

        // Deterministic: the same answer on every call.
        foreach (range(1, 3) as $ignored) {
            $this->assertSame($second->id, $this->owner());
        }
    }

    public function test_a_coordinator_with_several_assignments_ranks_by_their_most_specific_one(): void
    {
        $many = $this->coordinator('111111110');
        $groupOnly = $this->coordinator('211111110');
        $branchEarlier = $this->coordinator('222222220');
        $this->assignAt($many, $this->clan, '2026-01-01 00:00:00');
        $this->assignAt($many, $this->branch, '2026-06-01 00:00:00');
        $this->assignAt($groupOnly, $this->group, '2026-01-01 00:00:00');

        // many's BRANCH beats groupOnly's earlier GROUP; each user listed once.
        $this->assertSame([$many->id, $groupOnly->id], $this->ownership->candidateUserIds($this->family));
        $this->assertSame($many->id, $this->owner());

        // An earlier BRANCH assignment of someone else wins the BRANCH tie.
        $this->assignAt($branchEarlier, $this->branch, '2026-03-01 00:00:00');
        $this->assertSame([$branchEarlier->id, $many->id, $groupOnly->id], $this->ownership->candidateUserIds($this->family));
        $this->assertSame($branchEarlier->id, $this->owner());
    }

    // ----------------------------------------------------- eligibility skips

    public function test_an_ineligible_top_candidate_is_skipped_never_inherited(): void
    {
        $noRole = $this->coordinator('111111110', ['FAMILY_USER']);
        $lostHead = $this->coordinator('211111110');
        $eligible = $this->coordinator('222222220');
        $this->assignAt($noRole, $this->branch, '2026-01-01 00:00:00');
        $this->assignAt($lostHead, $this->branch, '2026-02-01 00:00:00');
        $this->assignAt($eligible, $this->group, '2026-03-01 00:00:00');
        // lostHead's own household is no longer ACTIVE: no family context, no Coordinator Space.
        $this->ownFamily($lostHead)->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();

        $this->assertSame([$noRole->id, $lostHead->id, $eligible->id], $this->ownership->candidateUserIds($this->family));
        $this->assertSame($eligible->id, $this->owner());
        $this->assertFalse($this->ownership->coordinatorMayManage($noRole->fresh(), $this->family));
        $this->assertFalse($this->ownership->coordinatorMayView($lostHead->fresh(), $this->family));
    }

    public function test_a_coordinator_may_view_but_never_owns_or_manages_their_own_household(): void
    {
        $self = $this->coordinator('111111110');
        $other = $this->coordinator('211111110');
        $own = $this->ownFamily($self);
        $own->forceFill(['clan_id' => $this->clan->id, 'branch_id' => $this->branch->id])->save();
        $this->assignAt($self, $this->branch, '2026-01-01 00:00:00');

        // In scope (CoordinatorScopes lists it): viewable, yet never theirs to manage.
        $context = app(CoordinatorScopes::class)->context($self->fresh());
        $this->assertTrue(app(CoordinatorScopes::class)->canAccessFamily($context, $own));
        $this->assertSame([$self->id], $this->ownership->candidateUserIds($own));
        $this->assertNull($this->owner($own), 'only candidate is the household itself: CENTRAL');
        $this->assertTrue($this->ownership->coordinatorMayView($self->fresh(), $own));
        $this->assertFalse($this->ownership->isPrimary($self->fresh(), $own));
        $this->assertFalse($this->ownership->coordinatorMayManage($self->fresh(), $own));

        // The next eligible coordinator becomes primary, even with a less specific, later assignment.
        $this->assignAt($other, $this->clan, '2026-09-01 00:00:00');
        $this->assertSame($other->id, $this->owner($own));
        $this->assertTrue($this->ownership->coordinatorMayManage($other->fresh(), $own));
        $this->assertTrue($this->ownership->coordinatorMayView($self->fresh(), $own));
        $this->assertFalse($this->ownership->coordinatorMayManage($self->fresh(), $own));
        // And self still owns the other families of the Branch.
        $this->assertSame($self->id, $this->owner());

        // Out of scope, the own household is not even viewable.
        CoordinatorScopeAssignment::query()->where('user_id', $self->id)
            ->update(['revoked_at' => now(), 'revoked_by' => User::factory()->create()->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assign($self, $this->clan('OTHER_CLAN'));
        $this->assertFalse($this->ownership->coordinatorMayView($self->fresh(), $own));
        $this->assertFalse($this->ownership->coordinatorMayManage($self->fresh(), $own));
    }

    public function test_removing_the_coordinator_role_moves_ownership_at_once(): void
    {
        $primary = $this->coordinator('111111110');
        $backup = $this->coordinator('211111110');
        $this->assignAt($primary, $this->branch, '2026-01-01 00:00:00');
        $this->assignAt($backup, $this->clan, '2026-01-01 00:00:00');
        $this->assertSame($primary->id, $this->owner());

        $primary->removeRole('COORDINATOR');
        $this->forgetPermissions();

        $this->assertSame($backup->id, $this->owner());
        $this->assertFalse($this->ownership->coordinatorMayView($primary->fresh(), $this->family));
        $this->assertFalse($this->ownership->coordinatorMayManage($primary->fresh(), $this->family));
    }

    public function test_without_the_coordinator_follow_up_permission_nobody_owns_and_it_is_central(): void
    {
        $primary = $this->coordinator('111111110');
        $this->assignAt($primary, $this->branch, '2026-01-01 00:00:00');
        $this->assertSame($primary->id, $this->owner());

        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-notification-followup.manage');
        $this->forgetPermissions();
        $this->assertNull($this->owner());
        $this->assertFalse($this->ownership->coordinatorMayManage($primary->fresh(), $this->family));
        $this->assertTrue($this->ownership->coordinatorMayView($primary->fresh(), $this->family), 'view is its own permission');

        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-notification-followup.view');
        $this->forgetPermissions();
        $this->assertFalse($this->ownership->coordinatorMayView($primary->fresh(), $this->family));
    }

    // -------------------------------------------------------- central fallback

    public function test_a_family_or_structure_outside_every_scope_is_central(): void
    {
        $primary = $this->coordinator('111111110');
        $this->assignAt($primary, $this->clan, '2026-01-01 00:00:00');
        $this->assertSame($primary->id, $this->owner());

        // Inactive Branch: the Family is outside every scope.
        $this->branch->forceFill(['is_active' => false])->save();
        $this->assertNull($this->owner());
        $this->branch->forceFill(['is_active' => true])->save();

        // Inactive Group of the Family's Branch.
        $this->group->forceFill(['is_active' => false])->save();
        $this->assertNull($this->owner());
        $this->group->forceFill(['is_active' => true])->save();
        $this->assertSame($primary->id, $this->owner());

        // A Family that left ACTIVE: CENTRAL, even through a stale model.
        $stale = $this->family->fresh();
        Family::query()->whereKey($this->family->id)->update(['status' => FamilyStatus::INACTIVE->value]);
        $this->assertNull($this->ownership->resolve($stale)->coordinator);
        $this->assertTrue($this->ownership->resolve($stale)->isCentral());
    }

    // ------------------------------------------------- view vs manage, staff

    public function test_other_in_scope_coordinators_view_only_and_central_roles_hold_the_central_permissions(): void
    {
        $primary = $this->coordinator('111111110');
        $secondary = $this->coordinator('211111110');
        $outside = $this->coordinator('222222220');
        $this->assignAt($primary, $this->branch, '2026-01-01 00:00:00');
        $this->assignAt($secondary, $this->clan, '2026-01-01 00:00:00');
        $this->assign($outside, $this->clan('OTHER_CLAN'));

        $this->assertTrue($this->ownership->isPrimary($primary->fresh(), $this->family));
        $this->assertTrue($this->ownership->coordinatorMayView($primary->fresh(), $this->family));
        $this->assertTrue($this->ownership->coordinatorMayManage($primary->fresh(), $this->family));

        $this->assertFalse($this->ownership->isPrimary($secondary->fresh(), $this->family));
        $this->assertTrue($this->ownership->coordinatorMayView($secondary->fresh(), $this->family));
        $this->assertFalse($this->ownership->coordinatorMayManage($secondary->fresh(), $this->family));

        $this->assertFalse($this->ownership->coordinatorMayView($outside->fresh(), $this->family));
        $this->assertFalse($this->ownership->coordinatorMayManage($outside->fresh(), $this->family));

        // A plain household head, a staff user or an administrator is never a Coordinator owner.
        foreach (['FAMILY_USER', 'SOCIAL_WORKER', 'ADMINISTRATOR', 'SUPER_ADMIN'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertFalse($this->ownership->coordinatorMayView($user, $this->family), $role);
            $this->assertFalse($this->ownership->coordinatorMayManage($user, $this->family), $role);
            $central = in_array($role, ['ADMINISTRATOR', 'SUPER_ADMIN'], true);
            $this->assertSame($central, $user->can('notification-followup.view'), $role);
            $this->assertSame($central, $user->can('notification-followup.manage'), $role);
        }
        // Coordinators never hold the central permissions.
        $this->assertFalse($primary->fresh()->can('notification-followup.manage'));
        $this->assertFalse($primary->fresh()->can('notification-followup.view'));
    }

    // --------------------------------------------------------------- parity

    public function test_candidates_agree_with_coordinator_scope_eligibility(): void
    {
        $scopes = app(CoordinatorScopes::class);
        $otherGroup = $this->group($this->clan);
        $otherBranch = $this->branch($this->clan, $otherGroup);
        $ungrouped = $this->branch($this->clan);
        $families = [
            $this->family,
            $this->familyIn($this->clan, $otherBranch),
            $this->familyIn($this->clan, $ungrouped),
            $this->familyIn($this->clan),
            $this->familyIn($this->clan('OTHER_CLAN')),
        ];
        $coordinators = [
            [$this->coordinator('111111110'), $this->clan],
            [$this->coordinator('211111110'), $this->group],
            [$this->coordinator('222222220'), $this->branch],
            [$this->coordinator('333333330'), $otherGroup],
            [$this->coordinator('444444440'), $ungrouped],
            [$this->coordinator('511111110'), $otherBranch],
        ];
        foreach ($coordinators as [$user, $target]) {
            $this->assign($user, $target);
        }
        $this->assign($coordinators[5][0], $this->branch, revoked: true);

        foreach ($families as $family) {
            $inScope = collect($coordinators)
                ->map(fn ($c) => $c[0]->fresh())
                ->filter(fn (User $u) => $scopes->canAccessFamily($scopes->context($u), $family))
                ->map(fn (User $u) => (int) $u->id)->sort()->values()->all();
            $candidates = collect($this->ownership->candidateUserIds($family))->sort()->values()->all();

            // Every user whose scope covers the Family is a candidate, and every
            // candidate's scope covers it (all structure here is active).
            $this->assertSame($inScope, $candidates, $family->family_code);
            $viewers = collect($coordinators)->map(fn ($c) => $c[0]->fresh())
                ->filter(fn (User $u) => $this->ownership->coordinatorMayView($u, $family))
                ->map(fn (User $u) => (int) $u->id)->sort()->values()->all();
            $this->assertSame($inScope, $viewers, $family->family_code);

            $owner = $this->owner($family);
            $this->assertSame($candidates === [] ? null : $this->ownership->candidateUserIds($family)[0], $owner);
        }
    }

    // ----------------------------------------------------- nothing persisted

    public function test_ownership_is_never_stored_and_resolving_writes_nothing(): void
    {
        $this->assertFalse(Schema::hasColumn('notification_followups', 'owner_user_id'));
        $this->assertFalse(Schema::hasColumn('notification_followups', 'assignee_user_id'));
        $primary = $this->coordinator('111111110');
        $this->assignAt($primary, $this->branch, '2026-01-01 00:00:00');

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $this->ownership->resolve($this->family);
        $this->ownership->coordinatorMayView($primary->fresh(), $this->family);
        $this->ownership->coordinatorMayManage($primary->fresh(), $this->family);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/', $sql, $sql);
        }
    }

    // --------------------------------------------------------- reason codes

    public function test_the_reason_catalogues_are_exactly_the_approved_codes(): void
    {
        $this->assertSame(['CALLED_INFORMED', 'CALLED_NO_ANSWER', 'VISITED', 'MESSAGE_LEFT', 'OTHER'],
            array_column(NotificationFollowupContactOutcome::cases(), 'value'));
        $this->assertSame(['INFORMED_BY_PHONE', 'INFORMED_IN_PERSON', 'FAMILY_UNREACHABLE', 'FAMILY_INACTIVE', 'NOT_APPLICABLE'],
            array_column(NotificationFollowupCloseReason::cases(), 'value'));

        $codes = [...NotificationFollowupContactOutcome::cases(), ...NotificationFollowupCloseReason::cases()];
        foreach ($codes as $code) {
            // The stored reason_code / close_reason_code format.
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]{1,49}$/', $code->value);
            // No contact or closure code impersonates a family acknowledgement or view.
            $this->assertDoesNotMatchRegularExpression('/ACKNOWLEDG|VIEWED|SATISFIED/', $code->value);
        }
        // A manual closure is its own outcome, never SATISFIED.
        $this->assertNotSame(NotificationFollowupOutcome::SATISFIED, NotificationFollowupOutcome::CLOSED_MANUALLY);
    }
}
