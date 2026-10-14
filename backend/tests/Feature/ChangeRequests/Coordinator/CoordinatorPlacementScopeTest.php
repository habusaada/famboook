<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Models\Branch;
use App\Models\Clan;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.3b: CoordinatorScopes::coversPlacement() — may a Coordinator propose
 * a NEW Family placed in this Clan / Branch? — and the SQL form used by
 * CoordinatorScopes::requests() for proposals without a Family. Both must
 * give the same answer for every case. Synthetic data only.
 */
class CoordinatorPlacementScopeTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private CoordinatorScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        $this->scopes = app(CoordinatorScopes::class);
    }

    /** coversPlacement() and requests() agree, and give $expected. */
    private function assertCovers(bool $expected, User $user, Clan $clan, ?Branch $branch, string $case): void
    {
        $context = $this->scopes->context($user->fresh());
        $this->assertSame($expected, $this->scopes->coversPlacement($context, $clan, $branch), "coversPlacement: {$case}");

        $request = $this->proposedFamily($clan, $branch, $user);
        $this->assertSame($expected, $this->scopes->requests($context)->whereKey($request->id)->exists(), "requests(): {$case}");
    }

    public function test_each_level_covers_its_own_placements_and_assignments_combine(): void
    {
        $group = $this->group($this->scopeClan);
        $grouped = $this->branch($this->scopeClan, $group);
        $sibling = $this->branch($this->scopeClan, $group);
        $ungrouped = $this->branch($this->scopeClan);
        $otherClan = $this->clan('OTHER_CLAN');
        $foreign = $this->branch($otherClan);

        // CLAN: its Clan, with or without a Branch.
        $this->assertCovers(true, $this->coordinatorUser, $this->scopeClan, null, 'clan, no branch');
        $this->assertCovers(true, $this->coordinatorUser, $this->scopeClan, $grouped, 'clan, grouped branch');
        $this->assertCovers(true, $this->coordinatorUser, $this->scopeClan, $ungrouped, 'clan, ungrouped branch');
        $this->assertCovers(false, $this->coordinatorUser, $otherClan, null, 'another clan');
        $this->assertCovers(false, $this->coordinatorUser, $otherClan, $foreign, 'another clan with branch');

        // BRANCH_GROUP: the Branches of that Group only; never Clan level.
        $groupCoordinator = $this->coordinator('511111110');
        $this->assign($groupCoordinator, $group);
        $this->assertCovers(true, $groupCoordinator, $this->scopeClan, $grouped, 'group, its branch');
        $this->assertCovers(true, $groupCoordinator, $this->scopeClan, $sibling, 'group, sibling branch');
        $this->assertCovers(false, $groupCoordinator, $this->scopeClan, $ungrouped, 'group, ungrouped branch');
        $this->assertCovers(false, $groupCoordinator, $this->scopeClan, null, 'group, branchless');

        // BRANCH: that Branch only; never Clan level.
        $branchCoordinator = $this->coordinator('611111110');
        $this->assign($branchCoordinator, $grouped);
        $this->assertCovers(true, $branchCoordinator, $this->scopeClan, $grouped, 'branch, itself');
        $this->assertCovers(false, $branchCoordinator, $this->scopeClan, $sibling, 'branch, sibling');
        $this->assertCovers(false, $branchCoordinator, $this->scopeClan, null, 'branch, branchless');

        // Combined: the union.
        $this->assign($branchCoordinator, $foreign);
        $this->assertCovers(true, $branchCoordinator, $otherClan, $foreign, 'union, second assignment');
        $this->assertCovers(true, $branchCoordinator, $this->scopeClan, $grouped, 'union, first assignment');
        $this->assertCovers(false, $branchCoordinator, $otherClan, null, 'union never widens to clan level');
    }

    public function test_a_branch_of_another_clan_is_never_covered(): void
    {
        $foreign = $this->branch($this->clan('OTHER_CLAN'));
        $context = $this->scopes->context($this->coordinatorUser);

        $this->assertFalse($this->scopes->coversPlacement($context, $this->scopeClan, $foreign));
    }

    public function test_inactive_structure_and_revoked_assignments_cover_nothing(): void
    {
        $group = $this->group($this->scopeClan);
        $grouped = $this->branch($this->scopeClan, $group);
        $plain = $this->branch($this->scopeClan);

        $plain->forceFill(['is_active' => false])->save();
        $this->assertCovers(false, $this->coordinatorUser, $this->scopeClan, $plain, 'inactive branch');
        $group->forceFill(['is_active' => false])->save();
        $this->assertCovers(false, $this->coordinatorUser, $this->scopeClan, $grouped, 'branch of an inactive group');
        $this->assertCovers(true, $this->coordinatorUser, $this->scopeClan, null, 'clan still active');

        $revoked = $this->coordinator('711111110');
        $this->assign($revoked, $this->scopeClan, revoked: true);
        $this->assign($revoked, $this->clan('OTHER_CLAN'));
        $this->assertCovers(false, $revoked, $this->scopeClan, null, 'revoked assignment');

        // An inactive Clan: no scope at all, so nothing is covered.
        $this->scopeClan->forceFill(['is_active' => false])->save();
        $context = $this->scopes->context($this->coordinatorUser->fresh());
        $this->assertFalse($context->allowed());
        $this->assertFalse($this->scopes->coversPlacement($context, $this->scopeClan, null));
        $this->assertSame(0, $this->scopes->requests($context)->count());
    }

    public function test_an_applied_proposal_follows_the_created_family(): void
    {
        $request = $this->proposedFamily();
        $family = $this->familyIn($this->scopeClan);
        $request->forceFill([
            'status' => 'APPLIED', 'created_family_id' => $family->id, 'reviewed_by' => $this->coordinatorUser->id, 'reviewed_at' => now(),
            'approved_by' => $this->coordinatorUser->id, 'approved_at' => now(), 'applied_by' => $this->coordinatorUser->id, 'applied_at' => now(),
        ])->saveQuietly();
        $context = $this->scopes->context($this->coordinatorUser->fresh());
        $this->assertTrue($this->scopes->requests($context)->whereKey($request->id)->exists());

        // The created Family moved out of the scope: the request goes with it.
        $family->forceFill(['clan_id' => $this->clan('OTHER_CLAN')->id])->save();
        $this->assertFalse($this->scopes->requests($context)->whereKey($request->id)->exists());
    }
}
