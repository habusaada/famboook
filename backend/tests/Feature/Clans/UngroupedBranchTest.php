<?php

namespace Tests\Feature\Clans;

use App\Actions\ManageClanStructureAction;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * A Branch Group is an optional organizational classification (docs/02
 * §7b-§7c, docs/03 §7a). A Branch belongs to its Clan; it may be created
 * ungrouped and later be assigned to, moved between or removed from Groups
 * of the SAME Clan. All names and codes are synthetic.
 */
class UngroupedBranchTest extends TestCase
{
    use RefreshDatabase;

    private Clan $clan;

    private Clan $other;

    private BranchGroup $groupA;

    private BranchGroup $groupB;

    private BranchGroup $otherGroup;

    private Branch $grouped;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->clan = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $this->groupA = BranchGroup::create(['clan_id' => $this->clan->id, 'code' => 'GA', 'name' => 'مجموعة أ', 'sort_order' => 1]);
        $this->groupB = BranchGroup::create(['clan_id' => $this->clan->id, 'code' => 'GB', 'name' => null, 'sort_order' => 2]);
        $this->grouped = Branch::create(['branch_group_id' => $this->groupA->id, 'clan_id' => $this->clan->id, 'code' => 'GROUPED', 'name' => 'فرع مصنف', 'sort_order' => 1]);

        $this->other = Clan::create(['code' => 'OTHER_CLAN', 'name' => 'عائلة تجريبية أخرى']);
        $this->otherGroup = BranchGroup::create(['clan_id' => $this->other->id, 'code' => 'GX', 'name' => 'مجموعة أخرى', 'sort_order' => 1]);
    }

    private function admin(string $role = 'SUPER_ADMIN'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function createBranch(array $body, ?Clan $clan = null, string $role = 'SUPER_ADMIN')
    {
        $clan ??= $this->clan;

        return $this->actingAs($this->admin($role))->postJson("/api/v1/clans/{$clan->uuid}/branches", $body);
    }

    /** @param array<string, mixed> $body */
    private function patchBranch(Branch $branch, array $body)
    {
        return $this->actingAs($this->admin())->patchJson("/api/v1/branches/{$branch->uuid}", $body);
    }

    private function ungrouped(string $code = 'LOOSE'): Branch
    {
        $uuid = $this->createBranch(['code' => $code, 'name' => "فرع {$code}"])->assertCreated()->json('data.id');

        return Branch::where('uuid', $uuid)->firstOrFail();
    }

    /** @param array<string, mixed> $lineage */
    private function register(array $lineage)
    {
        return $this->actingAs($this->admin('DATA_ENTRY'))->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01',
            'registration_source' => 'MANUAL_ENTRY',
            'household_head' => ['full_name' => 'رب أسرة تجريبي', 'gender' => 'MALE'],
            'residence' => ['city' => 'مدينة تجريبية'],
            ...$lineage,
        ]);
    }

    // ------------------------------------------------------------ 1. create

    public function test_creates_an_ungrouped_branch_directly_under_a_clan(): void
    {
        $this->createBranch(['code' => 'LOOSE', 'name' => 'فرع بلا مجموعة'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LOOSE')
            ->assertJsonPath('data.group', null)
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.is_active', true);

        $branch = Branch::where('code', 'LOOSE')->sole();
        $this->assertNull($branch->branch_group_id);
        $this->assertSame($this->clan->id, $branch->clan_id);
        $this->assertNull($branch->group);

        // A second ungrouped branch is ordered after it; grouped creation still works.
        $this->createBranch(['code' => 'LOOSE_2', 'name' => 'فرع ثان'])->assertJsonPath('data.sort_order', 2);
        $this->createBranch(['code' => 'IN_B', 'name' => 'فرع في ب', 'branch_group_id' => $this->groupB->uuid])
            ->assertCreated()->assertJsonPath('data.group.code', 'GB');
    }

    public function test_only_clan_managers_create_branches(): void
    {
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REVIEWER'] as $role) {
            $this->createBranch(['code' => "NO_$role", 'name' => 'x'], role: $role)->assertForbidden();
        }
        $this->createBranch(['code' => 'BY_ADMIN', 'name' => 'x'], role: 'ADMINISTRATOR')->assertCreated();
        $this->assertSame(0, Branch::where('code', 'like', 'NO_%')->count());
    }

    public function test_the_structure_lists_ungrouped_branches(): void
    {
        $this->ungrouped();

        $clan = collect($this->actingAs($this->admin('DATA_ENTRY'))->getJson('/api/v1/reference/clans')->json('data'))
            ->firstWhere('code', Clan::AL_BREEM);
        $this->assertSame(['LOOSE'], array_column($clan['ungrouped_branches'], 'code'));
        $this->assertSame(['GROUPED'], array_column($clan['branch_groups'][0]['branches'], 'code'));

        $tree = collect($this->actingAs($this->admin())->getJson('/api/v1/reference/clans?include_inactive=1')->json('data'))
            ->firstWhere('code', Clan::AL_BREEM);
        $this->assertSame(0, $tree['ungrouped_branches'][0]['family_count']);

        // The per-Clan branch list includes it (after the grouped ones).
        $this->actingAs($this->admin('DATA_ENTRY'))->getJson("/api/v1/clans/{$this->clan->uuid}/branches")
            ->assertOk()->assertJsonPath('data.0.code', 'GROUPED')
            ->assertJsonPath('data.1.code', 'LOOSE')->assertJsonPath('data.1.group', null);
    }

    // ------------------------------------------------------- 2. selectable

    public function test_an_active_ungrouped_branch_is_selectable(): void
    {
        $branch = $this->ungrouped();
        $this->assertTrue($branch->isSelectable());

        $branch->update(['is_active' => false]);
        $this->assertFalse($branch->fresh()->isSelectable());

        $branch->update(['is_active' => true]);
        $this->clan->update(['is_active' => false]);
        $this->assertFalse($branch->fresh()->isSelectable());
    }

    // ---------------------------------------------- 3-5. assign/move/remove

    public function test_assigns_moves_and_removes_a_branch_within_its_clan(): void
    {
        $branch = $this->ungrouped();

        $this->patchBranch($branch, ['branch_group_id' => $this->groupA->uuid])
            ->assertOk()->assertJsonPath('data.group.code', 'GA');
        $this->assertSame($this->groupA->id, $branch->fresh()->branch_group_id);

        $this->patchBranch($branch, ['branch_group_id' => $this->groupB->uuid])
            ->assertOk()->assertJsonPath('data.group.code', 'GB');
        $this->assertSame($this->groupB->id, $branch->fresh()->branch_group_id);

        // Omitting the key leaves the group unchanged.
        $this->patchBranch($branch, ['name' => 'اسم جديد'])->assertOk()->assertJsonPath('data.group.code', 'GB');

        $this->patchBranch($branch, ['branch_group_id' => null])
            ->assertOk()->assertJsonPath('data.group', null);

        $fresh = $branch->fresh();
        $this->assertNull($fresh->branch_group_id);
        // Clan and code never change as a side effect.
        $this->assertSame([$this->clan->id, 'LOOSE'], [$fresh->clan_id, $fresh->code]);
    }

    // --------------------------------------------------- 6. cross-clan

    public function test_rejects_a_group_of_another_clan(): void
    {
        $branch = $this->ungrouped();

        $this->patchBranch($branch, ['branch_group_id' => $this->otherGroup->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_group_id');
        $this->patchBranch($this->grouped, ['branch_group_id' => $this->otherGroup->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_group_id');
        $this->createBranch(['code' => 'CROSS', 'name' => 'x', 'branch_group_id' => $this->otherGroup->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_group_id');
        $this->createBranch(['code' => 'BAD_UUID', 'name' => 'x', 'branch_group_id' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_group_id');

        $this->assertNull($branch->fresh()->branch_group_id);
        $this->assertSame($this->groupA->id, $this->grouped->fresh()->branch_group_id);
        $this->assertFalse(Branch::whereIn('code', ['CROSS', 'BAD_UUID'])->exists());
    }

    public function test_the_domain_action_refuses_cross_clan_grouping(): void
    {
        $action = app(ManageClanStructureAction::class);

        try {
            $action->createBranch($this->clan, $this->otherGroup, ['code' => 'CROSS', 'name' => 'x']);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_group_id', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $action->updateBranch($this->grouped, ['branch_group' => $this->otherGroup]);
    }

    public function test_the_database_refuses_cross_clan_grouping_but_allows_null(): void
    {
        Branch::create(['branch_group_id' => null, 'clan_id' => $this->other->id, 'code' => 'DB_NULL', 'name' => 'x']);
        $this->assertTrue(Branch::where('code', 'DB_NULL')->exists());

        $this->expectException(QueryException::class);
        DB::table('branches')->where('code', 'DB_NULL')->update(['branch_group_id' => $this->groupA->id]);
    }

    // ------------------------------------------- 7. grouped keeps group rule

    public function test_a_grouped_branch_still_requires_an_active_group(): void
    {
        $this->assertTrue($this->grouped->fresh()->isSelectable());

        $this->groupA->update(['is_active' => false]);
        $this->assertFalse($this->grouped->fresh()->isSelectable());
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'GROUPED'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');

        // Removed from the inactive group, it is selectable again.
        $this->patchBranch($this->grouped, ['branch_group_id' => null])->assertOk();
        $this->assertTrue($this->grouped->fresh()->isSelectable());
    }

    // ------------------------------------------------ 8-10. family workflow

    public function test_a_family_can_use_an_ungrouped_branch(): void
    {
        $this->ungrouped();

        $code = $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'LOOSE'])
            ->assertCreated()
            ->assertJsonPath('data.branch.code', 'LOOSE')
            ->assertJsonPath('data.branch.group', null)
            ->json('data.family_code');

        $this->actingAs($this->admin())->getJson("/api/v1/families/{$code}")
            ->assertOk()->assertJsonPath('data.branch.code', 'LOOSE')->assertJsonPath('data.branch.group', null);

        // Edit: grouped ↔ ungrouped ↔ none.
        $family = Family::where('family_code', $code)->sole();
        $patch = fn (array $body) => $this->actingAs($this->admin('DATA_ENTRY'))->patchJson("/api/v1/families/{$code}", $body);
        $patch(['branch_code' => 'GROUPED'])->assertOk()->assertJsonPath('data.branch.group.code', 'GA');
        $patch(['branch_code' => 'LOOSE'])->assertOk()->assertJsonPath('data.branch.group', null);
        $patch(['branch_code' => null])->assertOk()->assertJsonPath('data.branch', null);
        $this->assertNull($family->fresh()->branch_id);
    }

    public function test_family_registration_works_with_grouped_ungrouped_and_no_branch(): void
    {
        $this->ungrouped();

        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'GROUPED'])->assertCreated()
            ->assertJsonPath('data.branch.group.code', 'GA');
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'LOOSE'])->assertCreated()
            ->assertJsonPath('data.branch.group', null);
        $this->register(['clan_code' => Clan::AL_BREEM])->assertCreated()
            ->assertJsonPath('data.branch', null);
    }

    public function test_a_family_and_its_ungrouped_branch_must_share_the_clan(): void
    {
        $this->ungrouped();

        $this->register(['clan_code' => 'OTHER_CLAN', 'branch_code' => 'LOOSE'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');

        // The database also refuses it (fk_families_branch_clan).
        $family = Family::factory()->create(['clan_id' => $this->other->id]);
        $this->expectException(QueryException::class);
        DB::table('families')->where('id', $family->id)->update(['branch_id' => Branch::where('code', 'LOOSE')->value('id')]);
    }

    // ----------------------------------------------------- reports / scope

    public function test_population_report_counts_ungrouped_branches(): void
    {
        $branch = $this->ungrouped();
        $family = Family::factory()->create(['clan_id' => $this->clan->id, 'branch_id' => $branch->id]);
        FamilyMembership::factory()->householdHead()->create(['family_id' => $family->id, 'person_id' => Person::factory()->create()->id]);
        FamilyResidence::factory()->create(['family_id' => $family->id]);
        $viewer = $this->admin();

        $this->actingAs($viewer)->getJson('/api/v1/reports/population?clan=AL_BREEM')->assertOk()
            ->assertJsonPath('data.organization.ungrouped.families', 1)
            ->assertJsonPath('data.organization.ungrouped.branches.0.code', 'LOOSE');

        // Scoped to the ungrouped branch itself.
        $this->actingAs($viewer)->getJson('/api/v1/reports/population?clan=AL_BREEM&branch=LOOSE')->assertOk()
            ->assertJsonPath('data.scope.branch.code', 'LOOSE')
            ->assertJsonPath('data.scope.branch_group', null);

        // Its scope options include it.
        $clan = collect($this->actingAs($viewer)->getJson('/api/v1/dashboard/scope-options')->json('data'))
            ->firstWhere('code', Clan::AL_BREEM);
        $this->assertSame(['LOOSE'], array_column($clan['ungrouped_branches'], 'code'));
    }

    // ------------------------------------------------------------- 11. migration

    public function test_migration_down_refuses_while_ungrouped_branches_exist(): void
    {
        $this->ungrouped();
        $migration = require database_path('migrations/2026_10_06_090000_make_branch_group_optional_on_branches.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
