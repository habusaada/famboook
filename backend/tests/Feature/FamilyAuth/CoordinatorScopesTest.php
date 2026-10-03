<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\CoordinatorAccessDenial;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1H: the Coordinator authorization service (docs/11 §8, §30a) — who is
 * a Coordinator right now, and the UNION of their scopes over the CURRENT
 * hierarchy. Synthetic data only.
 */
class CoordinatorScopesTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private CoordinatorScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->scopes = app(CoordinatorScopes::class);
    }

    private function denial(User $user): ?CoordinatorAccessDenial
    {
        return $this->scopes->context($user->fresh())->denial;
    }

    /** @return list<string> */
    private function visible(User $user): array
    {
        return $this->scopes->families($this->scopes->context($user->fresh()))->orderBy('family_code')->pluck('family_code')->all();
    }

    // -------------------------------------------------------- who qualifies

    public function test_role_permission_family_context_and_an_assignment_are_all_required(): void
    {
        $clan = $this->clan();
        $user = $this->coordinator();
        $this->assertSame(CoordinatorAccessDenial::NO_EFFECTIVE_SCOPE, $this->denial($user));

        $this->assign($user, $clan);
        $this->assertNull($this->denial($user));
    }

    public function test_an_assignment_without_the_role_grants_nothing(): void
    {
        $user = $this->coordinator(roles: ['FAMILY_USER']);
        $this->assign($user, $this->clan());

        $this->assertSame(CoordinatorAccessDenial::NOT_COORDINATOR, $this->denial($user));
        $this->assertSame([], $this->visible($user));
    }

    public function test_the_role_without_its_space_permission_grants_nothing(): void
    {
        $user = $this->coordinator();
        $this->assign($user, $this->clan());
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-space.access');

        $this->assertSame(CoordinatorAccessDenial::NO_SPACE_PERMISSION, $this->denial($user));
    }

    /** @return array<string, array{0: string}> */
    public static function lostEligibility(): array
    {
        return [
            'death recorded' => ['deceased'],
            'headship moved' => ['non-head'],
            'own family inactive' => ['family-inactive'],
            'own family deleted' => ['family-deleted'],
            'link suspended' => ['link-suspended'],
            'account deactivated' => ['user-inactive'],
            'staff role added (mixed account)' => ['mixed'],
            'family user role removed (coordinator only)' => ['coordinator-only'],
        ];
    }

    #[DataProvider('lostEligibility')]
    public function test_losing_the_own_family_context_ends_coordinator_access_at_once(string $case): void
    {
        $head = $this->activatedHead('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $user = $head['user'];
        $clan = $this->clan();
        $this->assign($user, $clan);
        $this->familyIn($clan);
        $this->assertNotEmpty($this->visible($user));

        match ($case) {
            'deceased' => $head['person']->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save(),
            'non-head' => $head['membership']->forceFill(['is_household_head' => false])->save(),
            'family-inactive' => $head['family']->forceFill(['status' => collect(FamilyStatus::cases())->first(fn ($s) => $s !== FamilyStatus::ACTIVE)])->save(),
            'family-deleted' => $head['family']->delete(),
            'link-suspended' => $head['link']->forceFill(['status' => 'SUSPENDED', 'suspended_at' => now(), 'suspension_reason' => 'ADMINISTRATIVE'])->save(),
            'user-inactive' => $user->forceFill(['is_active' => false])->save(),
            'mixed' => $user->assignRole('ADMINISTRATOR'),
            'coordinator-only' => $user->removeRole('FAMILY_USER'),
        };

        $this->assertSame(CoordinatorAccessDenial::NO_FAMILY_CONTEXT, $this->denial($user));
        $this->assertSame([], $this->visible($user));
    }

    public function test_removing_the_role_or_revoking_every_assignment_ends_access_at_once(): void
    {
        $clan = $this->clan();
        $user = $this->coordinator();
        $assignment = $this->assign($user, $clan);
        $this->assertNull($this->denial($user));

        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertSame(CoordinatorAccessDenial::NO_EFFECTIVE_SCOPE, $this->denial($user));

        $this->assign($user, $clan);
        $user->removeRole('COORDINATOR');
        $this->assertSame(CoordinatorAccessDenial::NOT_COORDINATOR, $this->denial($user));
    }

    // ------------------------------------------------------------ the scopes

    public function test_each_scope_level_selects_its_families(): void
    {
        $clan = $this->clan();
        $groupA = $this->group($clan);
        $inGroupA = $this->branch($clan, $groupA);
        $alsoInGroupA = $this->branch($clan, $groupA);
        $ungrouped = $this->branch($clan);
        $f1 = $this->familyIn($clan, $inGroupA);
        $f2 = $this->familyIn($clan, $alsoInGroupA);
        $f3 = $this->familyIn($clan, $ungrouped);
        $f4 = $this->familyIn($clan);
        $other = $this->familyIn($this->clan('OTHER_CLAN'));

        $byBranch = $this->coordinator('111111111');
        $this->assign($byBranch, $inGroupA);
        $this->assertSame([$f1->family_code], $this->visible($byBranch));

        $byGroup = $this->coordinator('222222222');
        $this->assign($byGroup, $groupA);
        $this->assertEqualsCanonicalizing([$f1->family_code, $f2->family_code], $this->visible($byGroup));

        // A whole Clan: grouped, ungrouped and unbranched families — no other Clan.
        $byClan = $this->coordinator('333333333');
        $this->assign($byClan, $clan);
        $visible = $this->visible($byClan);
        foreach ([$f1, $f2, $f3, $f4] as $family) {
            $this->assertContains($family->family_code, $visible);
        }
        $this->assertNotContains($other->family_code, $visible);
    }

    public function test_several_assignments_form_a_union_not_an_intersection(): void
    {
        $clanY = $this->clan('CLAN_Y');
        $clanZ = $this->clan('CLAN_Z');
        $branchA = $this->branch($clanZ);
        $branchB = $this->branch($clanZ);
        $branchC = $this->branch($clanZ);
        $inY = $this->familyIn($clanY);
        $inA = $this->familyIn($clanZ, $branchA);
        $inB = $this->familyIn($clanZ, $branchB);
        $inC = $this->familyIn($clanZ, $branchC);

        $user = $this->coordinator();
        $this->assign($user, $branchA);
        $this->assign($user, $branchB);
        $this->assertEqualsCanonicalizing([$inA->family_code, $inB->family_code], $this->visible($user));

        $this->assign($user, $clanY);
        $this->assertEqualsCanonicalizing([$inY->family_code, $inA->family_code, $inB->family_code], $this->visible($user));
        $this->assertFalse($this->scopes->canAccessFamily($this->scopes->context($user->fresh()), $inC));
    }

    public function test_a_family_without_a_branch_is_reached_only_through_its_clan(): void
    {
        $clan = $this->clan();
        $group = $this->group($clan);
        $unbranched = $this->familyIn($clan);

        $byGroup = $this->coordinator('111111111');
        $this->assign($byGroup, $group);
        $this->assertNotContains($unbranched->family_code, $this->visible($byGroup));

        $byClan = $this->coordinator('222222222');
        $this->assign($byClan, $clan);
        $this->assertContains($unbranched->family_code, $this->visible($byClan));
    }

    public function test_only_active_not_deleted_families_are_in_scope(): void
    {
        $clan = $this->clan();
        $active = $this->familyIn($clan);
        $inactive = $this->familyIn($clan, attributes: ['status' => collect(FamilyStatus::cases())->first(fn ($s) => $s !== FamilyStatus::ACTIVE)->value]);
        $deleted = $this->familyIn($clan);
        $deleted->delete();

        $user = $this->coordinator();
        $this->assign($user, $clan);

        $visible = $this->visible($user);
        $this->assertContains($active->family_code, $visible);
        $this->assertNotContains($inactive->family_code, $visible);
        $this->assertNotContains($deleted->family_code, $visible);
    }

    public function test_a_revoked_assignment_authorizes_nothing(): void
    {
        $clan = $this->clan();
        $branchA = $this->branch($clan);
        $branchB = $this->branch($clan);
        $inA = $this->familyIn($clan, $branchA);
        $inB = $this->familyIn($clan, $branchB);
        $user = $this->coordinator();
        $this->assign($user, $branchA);
        $this->assign($user, $branchB, revoked: true);

        $this->assertSame([$inA->family_code], $this->visible($user));
        $this->assertNotContains($inB->family_code, $this->visible($user));
        $this->assertCount(1, $this->scopes->context($user->fresh())->assignments);
    }

    // ------------------------------------------------- the current hierarchy

    public function test_a_moved_family_follows_its_current_branch(): void
    {
        $clan = $this->clan();
        $branchA = $this->branch($clan);
        $branchB = $this->branch($clan);
        $family = $this->familyIn($clan, $branchA);
        $user = $this->coordinator();
        $this->assign($user, $branchA);
        $this->assertSame([$family->family_code], $this->visible($user));

        $family->forceFill(['branch_id' => $branchB->id])->save();

        $this->assertSame([], $this->visible($user));
    }

    public function test_a_branch_moved_between_groups_follows_its_current_group(): void
    {
        $clan = $this->clan();
        $groupA = $this->group($clan);
        $groupB = $this->group($clan);
        $branch = $this->branch($clan, $groupA);
        $family = $this->familyIn($clan, $branch);
        $byA = $this->coordinator('111111111');
        $this->assign($byA, $groupA);
        $byB = $this->coordinator('222222222');
        $this->assign($byB, $groupB);
        $this->assertSame([$family->family_code], $this->visible($byA));
        $this->assertSame([], $this->visible($byB));

        $branch->forceFill(['branch_group_id' => $groupB->id])->save();

        $this->assertSame([], $this->visible($byA));
        $this->assertSame([$family->family_code], $this->visible($byB));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function inactiveStructure(): array
    {
        return [
            'clan scope, clan inactive' => ['clan', 'clan'],
            'group scope, group inactive' => ['group', 'group'],
            'group scope, family branch inactive' => ['group', 'branch'],
            'group scope, clan inactive' => ['group', 'clan'],
            'branch scope, branch inactive' => ['branch', 'branch'],
            "branch scope, the branch's group inactive" => ['branch', 'group'],
            'branch scope, clan inactive' => ['branch', 'clan'],
            'clan scope, the family branch inactive' => ['clan', 'branch'],
        ];
    }

    #[DataProvider('inactiveStructure')]
    public function test_inactive_structure_fails_closed(string $level, string $inactive): void
    {
        $clan = $this->clan();
        $group = $this->group($clan);
        $branch = $this->branch($clan, $group);
        $family = $this->familyIn($clan, $branch);
        $user = $this->coordinator();
        $this->assign($user, match ($level) {
            'clan' => $clan,
            'group' => $group,
            'branch' => $branch,
        });
        $this->assertSame([$family->family_code], $this->visible($user));

        DB::table(match ($inactive) {
            'clan' => 'clans',
            'group' => 'branch_groups',
            'branch' => 'branches',
        })->where('id', match ($inactive) {
            'clan' => $clan->id,
            'group' => $group->id,
            'branch' => $branch->id,
        })->update(['is_active' => false]);

        $this->assertSame([], $this->visible($user));
    }

    public function test_an_inactive_target_does_not_count_as_an_effective_assignment(): void
    {
        $clan = $this->clan();
        $user = $this->coordinator();
        $this->assign($user, $this->branch($clan, active: false));
        $this->assign($user, $this->group($clan, active: false));

        $this->assertSame(CoordinatorAccessDenial::NO_EFFECTIVE_SCOPE, $this->denial($user));
    }

    public function test_the_query_does_not_materialize_family_ids(): void
    {
        $clan = $this->clan();
        $user = $this->coordinator();
        $this->assign($user, $clan);

        $sql = $this->scopes->families($this->scopes->context($user->fresh()))->toSql();

        // One correlated EXISTS over the assignments; no literal id list.
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString('coordinator_scope_assignments', $sql);
        $this->assertStringNotContainsString(' in (', strtolower($sql));
    }

    public function test_a_denied_context_selects_no_family(): void
    {
        $clan = $this->clan();
        $this->familyIn($clan);
        $user = $this->coordinator(roles: ['FAMILY_USER']);
        $this->assign($user, $clan);

        $this->assertSame(0, $this->scopes->families($this->scopes->context($user))->count());
        $this->assertSame(0, Family::query()->whereRaw('1 = 0')->count());
    }

    public function test_the_summary_carries_codes_and_names_only(): void
    {
        $clan = $this->clan();
        $group = $this->group($clan, 'GRP_X');
        $branch = $this->branch($clan, null, 'BR_X');
        $user = $this->coordinator();
        $this->assign($user, $clan);
        $this->assign($user, $group);
        $this->assign($user, $branch);

        $summary = $this->scopes->summary($this->scopes->context($user->fresh()));

        $this->assertSame([
            ['type' => 'CLAN', 'code' => 'TEST_CLAN', 'name' => $clan->name, 'clan_name' => $clan->name],
            ['type' => 'BRANCH_GROUP', 'code' => 'GRP_X', 'name' => $group->name, 'clan_name' => $clan->name],
            ['type' => 'BRANCH', 'code' => 'BR_X', 'name' => $branch->name, 'clan_name' => $clan->name],
        ], $summary);
    }
}
