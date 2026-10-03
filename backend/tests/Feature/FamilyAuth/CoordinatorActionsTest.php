<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\AssignCoordinatorScopeAction;
use App\Actions\GrantCoordinatorRoleAction;
use App\Actions\RevokeCoordinatorRoleAction;
use App\Actions\RevokeCoordinatorScopeAction;
use App\Enums\CoordinatorRevokeReason;
use App\Enums\LifeStatus;
use App\Exceptions\CoordinatorException;
use App\Models\AuthSecurityEvent;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1H: coordinator administration as Domain Actions (docs/11 §8, §30a) —
 * grant and revoke the role, assign and revoke a scope. Staff-side holders of
 * coordinator-scope.manage only. Synthetic data only.
 */
class CoordinatorActionsTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->admin = $this->staff('ADMINISTRATOR');
    }

    private function staff(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
    }

    /** @return array{user: User, person: Person} */
    private function headAccount(string $nationalId = '111111111', array $roles = ['FAMILY_USER']): array
    {
        $head = $this->activatedHead($nationalId, $roles);

        return ['user' => $head['user'], 'person' => $head['person'], 'head' => $head];
    }

    private function grant(Person $person, ?User $actor = null): User
    {
        return app(GrantCoordinatorRoleAction::class)->handle($actor ?? $this->admin, $person);
    }

    private function assignTo(Person $person, $target, ?User $actor = null): CoordinatorScopeAssignment
    {
        return app(AssignCoordinatorScopeAction::class)->handle($actor ?? $this->admin, $person, $target);
    }

    private function refused(string $reason, callable $act): void
    {
        try {
            $act();
            $this->fail("Expected {$reason}.");
        } catch (CoordinatorException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // ------------------------------------------------------------- the actor

    public function test_only_super_admin_and_administrator_may_administer_coordinators(): void
    {
        ['person' => $person] = $this->headAccount();

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
            $this->assertTrue($this->staff($role)->can('coordinator-scope.manage'), $role);
        }
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            try {
                $this->grant($person, $this->staff($role));
                $this->fail("{$role} must not grant.");
            } catch (AuthorizationException) {
                $this->assertFalse($person->fresh() && User::role('COORDINATOR')->exists(), $role);
            }
        }
    }

    public function test_a_family_side_account_never_administers_coordinators_whatever_it_holds(): void
    {
        ['person' => $target] = $this->headAccount('111111111');
        $coordinator = $this->coordinator('222222222');
        $coordinator->givePermissionTo('coordinator-scope.manage');
        $inactiveAdmin = $this->staff('SUPER_ADMIN');
        $inactiveAdmin->forceFill(['is_active' => false])->save();

        foreach ([$coordinator, $inactiveAdmin] as $actor) {
            try {
                $this->grant($target, $actor);
                $this->fail('Refused.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------ the role

    public function test_the_role_is_granted_to_an_eligible_family_account_and_recorded(): void
    {
        ['user' => $user, 'person' => $person] = $this->headAccount();

        $this->grant($person);

        $this->assertEqualsCanonicalizing(['FAMILY_USER', 'COORDINATOR'], $user->fresh()->getRoleNames()->all());
        $this->assertSame(AccountSide::FAMILY, AccountSide::of($user->fresh()));
        // No scope is created: the role alone opens nothing.
        $this->assertSame(0, CoordinatorScopeAssignment::count());
        $this->assertFalse(app(CoordinatorScopes::class)->context($user->fresh())->allowed());

        $event = AuthSecurityEvent::where('event_type', 'COORDINATOR_ROLE_GRANTED')->sole();
        $this->assertSame([$user->id, $person->id, $this->admin->id], [$event->user_id, $event->person_id, $event->actor_user_id]);
    }

    public function test_the_role_is_refused_without_a_family_account_or_family_context(): void
    {
        [$neverActivated] = $this->eligibleHead('999999999');
        $this->refused(CoordinatorException::NO_FAMILY_ACCOUNT, fn () => $this->grant($neverActivated));

        ['person' => $dead] = $this->headAccount('111111111');
        $dead->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save();
        $this->refused(CoordinatorException::NOT_ELIGIBLE, fn () => $this->grant($dead));

        ['head' => $moved] = $this->headAccount('222222222');
        $moved['membership']->forceFill(['is_household_head' => false])->save();
        $this->refused(CoordinatorException::NOT_ELIGIBLE, fn () => $this->grant($moved['person']));

        $this->assertFalse(User::role('COORDINATOR')->exists());
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'COORDINATOR_ROLE_GRANTED')->count());
    }

    public function test_the_role_is_never_added_to_a_staff_or_mixed_account(): void
    {
        foreach (array_merge(StaffRoles::ALL, ['mixed']) as $i => $case) {
            ['user' => $user, 'person' => $person] = $this->headAccount('30000000'.$i);
            $case === 'mixed' ? $user->assignRole('ADMINISTRATOR') : $user->syncRoles([$case]);

            $this->refused(CoordinatorException::NOT_ELIGIBLE, fn () => $this->grant($person));
            $this->assertFalse($user->fresh()->hasRole('COORDINATOR'), $case);
        }
    }

    public function test_granting_twice_is_a_conflict(): void
    {
        ['person' => $person] = $this->headAccount();
        $this->grant($person);

        $this->refused(CoordinatorException::ALREADY_COORDINATOR, fn () => $this->grant($person));
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'COORDINATOR_ROLE_GRANTED')->count());
    }

    // ----------------------------------------------------------- the scopes

    public function test_a_scope_of_each_level_is_assigned_in_its_typed_columns(): void
    {
        ['user' => $user, 'person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $group = $this->group($clan);
        $branch = $this->branch($clan, $group);

        $byClan = $this->assignTo($person, $clan);
        $byGroup = $this->assignTo($person, $group);
        $byBranch = $this->assignTo($person, $branch);

        $this->assertSame(['CLAN', $clan->id, null, null], [$byClan->scope_type->value, $byClan->clan_id, $byClan->branch_group_id, $byClan->branch_id]);
        $this->assertSame(['BRANCH_GROUP', $clan->id, $group->id, null], [$byGroup->scope_type->value, $byGroup->clan_id, $byGroup->branch_group_id, $byGroup->branch_id]);
        $this->assertSame(['BRANCH', $clan->id, null, $branch->id], [$byBranch->scope_type->value, $byBranch->clan_id, $byBranch->branch_group_id, $byBranch->branch_id]);
        foreach ([$byClan, $byGroup, $byBranch] as $assignment) {
            $this->assertSame([$this->admin->id, $user->id], [$assignment->assigned_by, $assignment->user_id]);
            $this->assertNotNull($assignment->assigned_at);
        }
        $this->assertSame(['CLAN', 'BRANCH_GROUP', 'BRANCH'], AuthSecurityEvent::where('event_type', 'COORDINATOR_SCOPE_ASSIGNED')->orderBy('id')->get()->pluck('metadata.scope_type')->all());
        $this->assertCount(3, app(CoordinatorScopes::class)->context($user->fresh())->assignments);
    }

    public function test_a_scope_requires_the_role_first(): void
    {
        ['person' => $person] = $this->headAccount();

        $this->refused(CoordinatorException::NOT_COORDINATOR, fn () => $this->assignTo($person, $this->clan()));
        $this->assertSame(0, CoordinatorScopeAssignment::count());
    }

    public function test_a_scope_requires_the_account_to_still_be_eligible(): void
    {
        ['head' => $head, 'person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $head['family']->delete();

        $this->refused(CoordinatorException::NOT_ELIGIBLE, fn () => $this->assignTo($person, $this->clan()));
    }

    public function test_a_scope_on_an_inactive_target_is_refused(): void
    {
        ['person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $inactiveClan = $this->clan('OFF_CLAN', active: false);
        $clan = $this->clan();
        $inactiveGroup = $this->group($clan, active: false);
        $inactiveBranch = $this->branch($clan, active: false);
        $branchInInactiveGroup = $this->branch($clan, $inactiveGroup);
        $groupInInactiveClan = $this->group($inactiveClan);

        foreach ([$inactiveClan, $inactiveGroup, $inactiveBranch, $branchInInactiveGroup, $groupInInactiveClan] as $target) {
            $this->refused(CoordinatorException::TARGET_INACTIVE, fn () => $this->assignTo($person, $target));
        }
        $this->assertSame(0, CoordinatorScopeAssignment::count());
    }

    public function test_the_same_active_scope_twice_is_a_conflict_but_after_revocation_it_may_return(): void
    {
        ['person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $branch = $this->branch($this->clan());
        $first = $this->assignTo($person, $branch);

        $this->refused(CoordinatorException::SCOPE_EXISTS, fn () => $this->assignTo($person, $branch));

        app(RevokeCoordinatorScopeAction::class)->handle($this->admin, $first, CoordinatorRevokeReason::SCOPE_CHANGED);
        $second = $this->assignTo($person, $branch);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, CoordinatorScopeAssignment::count());
    }

    public function test_revoking_a_scope_keeps_the_row_with_who_when_and_why(): void
    {
        ['user' => $user, 'person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $assignment = $this->assignTo($person, $this->clan());

        app(RevokeCoordinatorScopeAction::class)->handle($this->admin, $assignment, CoordinatorRevokeReason::ADMINISTRATIVE);

        $fresh = $assignment->fresh();
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame([$this->admin->id, 'ADMINISTRATIVE'], [$fresh->revoked_by, $fresh->revoke_reason]);
        $this->assertSame(1, CoordinatorScopeAssignment::count());
        $this->assertFalse(app(CoordinatorScopes::class)->context($user->fresh())->allowed());
        $event = AuthSecurityEvent::where('event_type', 'COORDINATOR_SCOPE_REVOKED')->sole();
        $this->assertSame(['ADMINISTRATIVE', 'CLAN'], [$event->reason_code, $event->metadata['scope_type']]);

        $this->refused(CoordinatorException::ALREADY_REVOKED, fn () => app(RevokeCoordinatorScopeAction::class)->handle($this->admin, $assignment, CoordinatorRevokeReason::ADMINISTRATIVE));
    }

    public function test_role_removed_is_never_chosen_by_a_caller(): void
    {
        ['person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $assignment = $this->assignTo($person, $this->clan());

        $this->expectException(InvalidArgumentException::class);
        app(RevokeCoordinatorScopeAction::class)->handle($this->admin, $assignment, CoordinatorRevokeReason::ROLE_REMOVED);
    }

    // ------------------------------------------------------- revoking the role

    public function test_revoking_the_role_revokes_every_active_scope_in_one_transaction(): void
    {
        ['user' => $user, 'person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $a = $this->assignTo($person, $clan);
        $b = $this->assignTo($person, $this->branch($clan));
        $old = $this->assignTo($person, $this->group($clan));
        app(RevokeCoordinatorScopeAction::class)->handle($this->admin, $old, CoordinatorRevokeReason::SCOPE_CHANGED);

        app(RevokeCoordinatorRoleAction::class)->handle($this->admin, $person, CoordinatorRevokeReason::NO_LONGER_ELIGIBLE);

        $this->assertSame(['FAMILY_USER'], $user->fresh()->getRoleNames()->all());
        foreach ([$a, $b] as $assignment) {
            $this->assertSame('ROLE_REMOVED', $assignment->fresh()->revoke_reason);
        }
        // An earlier revocation keeps its own reason.
        $this->assertSame('SCOPE_CHANGED', $old->fresh()->revoke_reason);
        $this->assertSame(3, CoordinatorScopeAssignment::count());
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'COORDINATOR_ROLE_REVOKED')->where('reason_code', 'NO_LONGER_ELIGIBLE')->count());
        $this->assertSame(2, AuthSecurityEvent::where('event_type', 'COORDINATOR_SCOPE_REVOKED')->where('reason_code', 'ROLE_REMOVED')->count());
        // The household stays: only Coordinator Space goes.
        $this->assertTrue(app(FamilyAccessResolver::class)->familyContext($user->fresh())->hasFamilyContext());
    }

    public function test_a_failure_while_revoking_the_role_leaves_everything_as_it_was(): void
    {
        ['user' => $user, 'person' => $person] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $assignment = $this->assignTo($person, $this->clan());
        // The role removal itself fails, after the scopes were revoked.
        AuthSecurityEvent::creating(function (AuthSecurityEvent $event) {
            if ($event->event_type->value === 'COORDINATOR_ROLE_REVOKED') {
                throw new RuntimeException('synthetic failure');
            }
        });

        try {
            app(RevokeCoordinatorRoleAction::class)->handle($this->admin, $person, CoordinatorRevokeReason::ADMINISTRATIVE);
            $this->fail('Expected the synthetic failure.');
        } catch (RuntimeException) {
        }

        $this->assertTrue($user->fresh()->hasRole('COORDINATOR'));
        $this->assertNull($assignment->fresh()->revoked_at);
    }

    public function test_the_role_can_be_removed_from_an_account_that_is_no_longer_eligible(): void
    {
        ['user' => $user, 'person' => $person, 'head' => $head] = $this->headAccount('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $this->assignTo($person, $this->clan());
        $head['link']->forceFill(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save();

        app(RevokeCoordinatorRoleAction::class)->handle($this->admin, $person, CoordinatorRevokeReason::NO_LONGER_ELIGIBLE);

        $this->assertFalse($user->fresh()->hasRole('COORDINATOR'));
        $this->assertSame(0, CoordinatorScopeAssignment::query()->active()->count());
    }

    public function test_revoking_a_role_that_is_not_held_is_a_conflict(): void
    {
        ['person' => $person] = $this->headAccount();

        $this->refused(CoordinatorException::NOT_COORDINATOR, fn () => app(RevokeCoordinatorRoleAction::class)->handle($this->admin, $person, CoordinatorRevokeReason::ADMINISTRATIVE));
    }
}
