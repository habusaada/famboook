<?php

namespace Tests\Feature\Clans;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\ClanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Clan → Branch Group → Branch structure (docs/02 §7a–§7c, docs/03 §7a,
 * docs/06 §56a / AUTH-ADR-054). All names and codes are synthetic.
 */
class ClanStructureTest extends TestCase
{
    use RefreshDatabase;

    private Clan $alBreem;

    private Clan $other;

    private BranchGroup $namedGroup;

    private BranchGroup $unnamedGroup;

    private Branch $branchA;

    private Branch $branchB;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        // AL_BREEM comes from the backfill migration. The approved taxonomy
        // (ClanSeeder) is not loaded here so these synthetic fixtures stay
        // isolated; ClanSeederTest covers the seed data.

        $this->alBreem = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $this->namedGroup = BranchGroup::create(['clan_id' => $this->alBreem->id, 'code' => 'G01', 'name' => 'مجموعة تجريبية', 'sort_order' => 1]);
        $this->unnamedGroup = BranchGroup::create(['clan_id' => $this->alBreem->id, 'code' => 'G02', 'name' => null, 'sort_order' => 2]);
        $this->branchA = Branch::create(['branch_group_id' => $this->namedGroup->id, 'clan_id' => $this->alBreem->id, 'code' => 'BR_A', 'name' => 'فرع أ', 'sort_order' => 1]);
        $this->branchB = Branch::create(['branch_group_id' => $this->unnamedGroup->id, 'clan_id' => $this->alBreem->id, 'code' => 'BR_B', 'name' => 'فرع ب', 'sort_order' => 1]);

        $this->other = Clan::create(['code' => 'TEST_CLAN', 'name' => 'عائلة تجريبية أخرى']);
        $otherGroup = BranchGroup::create(['clan_id' => $this->other->id, 'code' => 'G01', 'name' => null, 'sort_order' => 1]);
        $this->otherBranch = Branch::create(['branch_group_id' => $otherGroup->id, 'clan_id' => $this->other->id, 'code' => 'BR_X', 'name' => 'فرع س', 'sort_order' => 1]);
    }

    private function user(string $role = 'SUPER_ADMIN'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function registerPayload(array $lineage): array
    {
        return [
            'registration_date' => '2026-09-01',
            'registration_source' => 'MANUAL_ENTRY',
            'household_head' => [
                'full_name' => 'رب أسرة تجريبي',
                'gender' => 'MALE',
                'birth_date' => '1980-01-01',
            ],
            ...$lineage,
            'residence' => ['governorate' => 'محافظة تجريبية', 'city' => 'مدينة تجريبية'],
        ];
    }

    private function register(array $lineage, string $role = 'SUPER_ADMIN')
    {
        return $this->actingAs($this->user($role))->postJson('/api/v1/families', $this->registerPayload($lineage));
    }

    private function family(array $attributes = []): Family
    {
        $family = Family::factory()->create($attributes);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create()->id,
        ]);
        FamilyResidence::factory()->create(['family_id' => $family->id]);

        return $family;
    }

    private function patchFamily(Family $family, array $payload, string $role = 'SUPER_ADMIN')
    {
        return $this->actingAs($this->user($role))->patchJson("/api/v1/families/{$family->family_code}", $payload);
    }

    // ---------------------------------------------------------- structure

    public function test_seeded_al_breem_clan_exists_and_is_active(): void
    {
        $this->assertSame('عائلة البريم', $this->alBreem->name);
        $this->assertTrue($this->alBreem->is_active);
        // The seeder is idempotent.
        $this->seed(ClanSeeder::class);
        $this->assertSame(1, Clan::where('code', Clan::AL_BREEM)->count());
    }

    public function test_reference_lists_active_clans_with_nested_hierarchy(): void
    {
        $response = $this->actingAs($this->user('DATA_ENTRY'))->getJson('/api/v1/reference/clans')->assertOk();

        $clan = collect($response->json('data'))->firstWhere('code', Clan::AL_BREEM);
        $this->assertSame($this->alBreem->uuid, $clan['id']);
        $this->assertSame(['G01', 'G02'], array_column($clan['branch_groups'], 'code'));
        $this->assertSame(['BR_A'], array_column($clan['branch_groups'][0]['branches'], 'code'));
        $this->assertArrayNotHasKey('clan_id', $clan['branch_groups'][0]);
        $this->assertArrayNotHasKey('branch_group_id', $clan['branch_groups'][0]['branches'][0]);
    }

    public function test_unnamed_group_is_valid_and_displays_its_branch_names(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/v1/reference/clans')->assertOk();
        $clan = collect($response->json('data'))->firstWhere('code', Clan::AL_BREEM);

        $this->assertNull($clan['branch_groups'][1]['name']);
        $this->assertSame('فرع ب', $clan['branch_groups'][1]['display_name']);
        $this->assertSame('مجموعة تجريبية', $clan['branch_groups'][0]['display_name']);
    }

    public function test_reference_hides_inactive_items(): void
    {
        $this->branchA->update(['is_active' => false]);
        $this->unnamedGroup->update(['is_active' => false]);
        $this->other->update(['is_active' => false]);

        $response = $this->actingAs($this->user())->getJson('/api/v1/reference/clans')->assertOk();

        $this->assertSame([Clan::AL_BREEM], array_column($response->json('data'), 'code'));
        $groups = $response->json('data.0.branch_groups');
        $this->assertSame(['G01'], array_column($groups, 'code'));
        $this->assertSame([], $groups[0]['branches']);
    }

    public function test_lists_groups_and_branches_of_a_clan(): void
    {
        $user = $this->user('SOCIAL_WORKER');

        $this->actingAs($user)->getJson("/api/v1/clans/{$this->alBreem->uuid}/branch-groups")
            ->assertOk()->assertJsonPath('data.0.code', 'G01')->assertJsonPath('data.1.name', null);

        $this->actingAs($user)->getJson("/api/v1/clans/{$this->alBreem->uuid}/branches")
            ->assertOk()->assertJsonPath('data.0.code', 'BR_A')->assertJsonPath('data.1.code', 'BR_B')
            ->assertJsonPath('data.1.group.code', 'G02');

        $this->actingAs($user)->getJson("/api/v1/clans/{$this->alBreem->uuid}/branches?group={$this->unnamedGroup->uuid}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'BR_B');
    }

    public function test_branch_must_share_its_group_clan_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        Branch::create(['branch_group_id' => $this->namedGroup->id, 'clan_id' => $this->other->id, 'code' => 'BAD', 'name' => 'x']);
    }

    // -------------------------------------------------------- permissions

    public function test_operational_roles_can_view_structure(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/clans')->assertOk();
        }
        foreach (['REVIEWER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/clans')->assertForbidden();
        }
    }

    public function test_only_super_admin_and_administrator_manage(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $i => $role) {
            $this->actingAs($this->user($role))->postJson('/api/v1/clans', ['code' => "NEW_$i", 'name' => 'عائلة تجريبية'])
                ->assertCreated();
        }
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REVIEWER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->postJson('/api/v1/clans', ['code' => 'DENIED', 'name' => 'x'])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/branches/{$this->branchA->uuid}", ['is_active' => false])->assertForbidden();
        }
        // The full tree (with inactive items) is a management view.
        $this->actingAs($this->user('DATA_ENTRY'))->getJson('/api/v1/reference/clans?include_inactive=1')->assertForbidden();
        $this->actingAs($this->user('ADMINISTRATOR'))->getJson('/api/v1/reference/clans?include_inactive=1')->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/reference/clans')->assertUnauthorized();
        $this->postJson('/api/v1/clans', ['code' => 'X', 'name' => 'x'])->assertUnauthorized();
    }

    // --------------------------------------------------------- management

    public function test_manage_clan_group_and_branch(): void
    {
        $admin = $this->user('ADMINISTRATOR');

        $clanUuid = $this->actingAs($admin)->postJson('/api/v1/clans', ['code' => 'SYN_CLAN', 'name' => 'عائلة اصطناعية'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');

        $groupUuid = $this->actingAs($admin)->postJson("/api/v1/clans/$clanUuid/branch-groups", ['code' => 'G01'])
            ->assertCreated()->assertJsonPath('data.name', null)->assertJsonPath('data.sort_order', 1)->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/clans/$clanUuid/branch-groups", ['code' => 'G02', 'name' => 'مجموعة'])
            ->assertCreated()->assertJsonPath('data.sort_order', 2);

        $branchUuid = $this->actingAs($admin)->postJson("/api/v1/branch-groups/$groupUuid/branches", ['code' => 'B1', 'name' => 'فرع اصطناعي'])
            ->assertCreated()->assertJsonPath('data.sort_order', 1)->json('data.id');

        $branch = Branch::where('uuid', $branchUuid)->firstOrFail();
        $this->assertSame(Clan::where('uuid', $clanUuid)->value('id'), $branch->clan_id);

        $this->actingAs($admin)->patchJson("/api/v1/clans/$clanUuid", ['name' => 'اسم معدل', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'اسم معدل')->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->patchJson("/api/v1/branch-groups/$groupUuid", ['name' => 'مسماة الآن', 'sort_order' => 5])
            ->assertOk()->assertJsonPath('data.sort_order', 5);
        $this->actingAs($admin)->patchJson("/api/v1/branch-groups/$groupUuid", ['name' => null])
            ->assertOk()->assertJsonPath('data.name', null);
        $this->actingAs($admin)->patchJson("/api/v1/branches/$branchUuid", ['name' => 'فرع معدل', 'is_active' => false, 'sort_order' => 3])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.sort_order', 3);
        $this->actingAs($admin)->patchJson("/api/v1/branches/$branchUuid", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_management_validation(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->postJson('/api/v1/clans', ['code' => Clan::AL_BREEM, 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/api/v1/clans', ['code' => 'bad code', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/api/v1/clans', ['code' => 'NO_NAME'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        // Group codes are unique per Clan; the same code in another Clan is fine.
        $this->actingAs($admin)->postJson("/api/v1/clans/{$this->alBreem->uuid}/branch-groups", ['code' => 'G01'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson("/api/v1/clans/{$this->other->uuid}/branch-groups", ['code' => 'G02'])
            ->assertCreated();

        // Branch codes are unique per Clan (across its groups).
        $this->actingAs($admin)->postJson("/api/v1/branch-groups/{$this->unnamedGroup->uuid}/branches", ['code' => 'BR_A', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson("/api/v1/branch-groups/{$this->unnamedGroup->uuid}/branches", ['code' => 'BR_C'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        // Codes are immutable; structures do not move.
        $this->actingAs($admin)->patchJson("/api/v1/clans/{$this->alBreem->uuid}", ['code' => 'RENAMED'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->patchJson("/api/v1/branches/{$this->branchA->uuid}", ['code' => 'RENAMED'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->patchJson("/api/v1/branches/{$this->branchA->uuid}", ['branch_group_id' => $this->unnamedGroup->id])
            ->assertUnprocessable();
        $this->assertSame('BR_A', $this->branchA->fresh()->code);
    }

    public function test_structures_cannot_be_deleted(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->deleteJson("/api/v1/clans/{$this->alBreem->uuid}")->assertMethodNotAllowed();
        $this->actingAs($admin)->deleteJson("/api/v1/branch-groups/{$this->namedGroup->uuid}")->assertMethodNotAllowed();
        $this->actingAs($admin)->deleteJson("/api/v1/branches/{$this->branchA->uuid}")->assertMethodNotAllowed();

        // The database also refuses to delete a referenced structure.
        $this->family(['clan_id' => $this->alBreem->id, 'branch_id' => $this->branchA->id]);
        $this->expectException(QueryException::class);
        DB::table('branches')->where('id', $this->branchA->id)->delete();
    }

    // -------------------------------------------------- family registration

    public function test_registration_requires_a_clan(): void
    {
        $this->register([])->assertUnprocessable()->assertJsonValidationErrors('clan_code');
        $this->register(['clan_code' => 'UNKNOWN'])->assertUnprocessable()->assertJsonValidationErrors('clan_code');
        $this->assertSame(0, Family::count());
    }

    public function test_registration_with_clan_and_no_branch(): void
    {
        $response = $this->register(['clan_code' => Clan::AL_BREEM], 'DATA_ENTRY')->assertCreated()
            ->assertJsonPath('data.clan.code', Clan::AL_BREEM)
            ->assertJsonPath('data.clan.name', 'عائلة البريم')
            ->assertJsonPath('data.branch', null);

        $this->assertArrayNotHasKey('clan_id', $response->json('data'));
        $family = Family::where('family_code', $response->json('data.family_code'))->firstOrFail();
        $this->assertSame($this->alBreem->id, $family->clan_id);
        $this->assertNull($family->branch_id);
    }

    public function test_registration_with_clan_and_branch(): void
    {
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'BR_B'])->assertCreated()
            ->assertJsonPath('data.branch.code', 'BR_B')
            ->assertJsonPath('data.branch.name', 'فرع ب')
            ->assertJsonPath('data.branch.group.code', 'G02')
            ->assertJsonPath('data.branch.group.name', null)
            ->assertJsonPath('data.branch.group.display_name', 'فرع ب');

        $this->register(['clan_code' => 'TEST_CLAN', 'branch_code' => 'BR_X'])->assertCreated()
            ->assertJsonPath('data.clan.code', 'TEST_CLAN');
    }

    public function test_branch_must_belong_to_the_selected_clan(): void
    {
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'BR_X'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        $this->register(['clan_code' => 'TEST_CLAN', 'branch_code' => 'BR_A'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        $this->assertSame(0, Family::count());
    }

    public function test_inactive_structures_cannot_be_newly_selected(): void
    {
        $this->branchA->update(['is_active' => false]);
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'BR_A'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');

        // A branch inside an inactive group is not selectable either.
        $this->unnamedGroup->update(['is_active' => false]);
        $this->register(['clan_code' => Clan::AL_BREEM, 'branch_code' => 'BR_B'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');

        $this->other->update(['is_active' => false]);
        $this->register(['clan_code' => 'TEST_CLAN'])
            ->assertUnprocessable()->assertJsonValidationErrors('clan_code');
    }

    public function test_registration_does_not_change_identifier_behaviour(): void
    {
        $first = $this->register(['clan_code' => Clan::AL_BREEM])->assertCreated();
        $second = $this->register(['clan_code' => 'TEST_CLAN', 'branch_code' => 'BR_X'])->assertCreated();

        $a = Family::where('family_code', $first->json('data.family_code'))->firstOrFail();
        $b = Family::where('family_code', $second->json('data.family_code'))->firstOrFail();
        // One global FAM sequence regardless of Clan; codes follow the ids.
        $this->assertSame(sprintf('FAM-%06d', $a->id), $a->family_code);
        $this->assertSame(sprintf('FAM-%06d', $b->id), $b->family_code);
        $this->assertSame($a->id + 1, $b->id);
    }

    // ---------------------------------------------------------- family edit

    public function test_existing_family_keeps_and_displays_an_inactive_branch(): void
    {
        $family = $this->family(['branch_id' => $this->branchA->id]);
        $this->branchA->update(['is_active' => false]);

        $this->actingAs($this->user())->getJson("/api/v1/families/{$family->family_code}")->assertOk()
            ->assertJsonPath('data.branch.code', 'BR_A')
            ->assertJsonPath('data.branch.is_active', false)
            ->assertJsonPath('data.branch.group.display_name', 'مجموعة تجريبية');

        // Unrelated edits and re-sending the unchanged branch are accepted.
        $this->patchFamily($family, ['notes' => 'ملاحظة'])->assertOk()->assertJsonPath('data.branch.code', 'BR_A');
        $this->patchFamily($family, ['clan_code' => Clan::AL_BREEM, 'branch_code' => 'BR_A'])->assertOk();
        $this->assertSame($this->branchA->id, $family->fresh()->branch_id);

        // Once cleared, it cannot be selected again.
        $this->patchFamily($family, ['branch_code' => null])->assertOk()->assertJsonPath('data.branch', null);
        $this->patchFamily($family, ['branch_code' => 'BR_A'])->assertUnprocessable()->assertJsonValidationErrors('branch_code');
    }

    public function test_update_changes_branch_within_the_clan_and_logs_it(): void
    {
        $family = $this->family();
        $this->assertSame($this->alBreem->id, $family->clan_id);

        $this->patchFamily($family, ['branch_code' => 'BR_A'], 'SOCIAL_WORKER')->assertOk()
            ->assertJsonPath('data.branch.code', 'BR_A');
        $this->patchFamily($family, ['branch_code' => 'BR_B'], 'DATA_ENTRY')->assertOk()
            ->assertJsonPath('data.branch.code', 'BR_B');

        $this->assertSame(
            ['FAMILY_UPDATED', 'FAMILY_UPDATED'],
            FamilyActivity::where('family_id', $family->id)->orderBy('id')->pluck('event_type')->map->value->all()
        );

        // A no-op save records nothing.
        $this->patchFamily($family, ['branch_code' => 'BR_B'])->assertOk();
        $this->assertSame(2, FamilyActivity::where('family_id', $family->id)->count());
    }

    public function test_choosing_a_branch_never_moves_the_family_to_another_clan(): void
    {
        $family = $this->family();

        $this->patchFamily($family, ['branch_code' => 'BR_X'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        $this->assertSame($this->alBreem->id, $family->fresh()->clan_id);
    }

    public function test_changing_clan_handles_an_incompatible_branch(): void
    {
        $family = $this->family(['branch_id' => $this->branchA->id]);

        // Silent: the old branch would no longer match — rejected.
        $this->patchFamily($family, ['clan_code' => 'TEST_CLAN'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        // Explicit incompatible branch — rejected.
        $this->patchFamily($family, ['clan_code' => 'TEST_CLAN', 'branch_code' => 'BR_A'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        $fresh = $family->fresh();
        $this->assertSame([$this->alBreem->id, $this->branchA->id], [$fresh->clan_id, $fresh->branch_id]);

        // Compatible branch.
        $this->patchFamily($family, ['clan_code' => 'TEST_CLAN', 'branch_code' => 'BR_X'])->assertOk()
            ->assertJsonPath('data.clan.code', 'TEST_CLAN')->assertJsonPath('data.branch.code', 'BR_X');

        // Clearing the branch while changing clan.
        $this->patchFamily($family, ['clan_code' => Clan::AL_BREEM, 'branch_code' => null])->assertOk()
            ->assertJsonPath('data.clan.code', Clan::AL_BREEM)->assertJsonPath('data.branch', null);

        // Without a branch, the clan can change on its own.
        $this->patchFamily($family, ['clan_code' => 'TEST_CLAN'])->assertOk()->assertJsonPath('data.branch', null);
    }

    public function test_clan_cannot_be_cleared(): void
    {
        $family = $this->family();

        $this->patchFamily($family, ['clan_code' => null])->assertUnprocessable()->assertJsonValidationErrors('clan_code');
        $this->assertSame($this->alBreem->id, $family->fresh()->clan_id);
    }

    public function test_database_rejects_a_branch_of_another_clan(): void
    {
        $family = $this->family();

        $this->expectException(QueryException::class);
        DB::table('families')->where('id', $family->id)->update(['branch_id' => $this->otherBranch->id]);
    }

    public function test_family_list_shows_compact_clan_and_branch(): void
    {
        $this->family(['branch_id' => $this->branchB->id]);

        $row = $this->actingAs($this->user())->getJson('/api/v1/families')->assertOk()->json('data.0');
        $this->assertSame('عائلة البريم', $row['clan_name']);
        $this->assertSame('فرع ب', $row['branch_name']);
        $this->assertArrayNotHasKey('clan_id', $row);
        $this->assertArrayNotHasKey('branch_id', $row);
    }

    // ------------------------------------------------------------ backfill

    public function test_backfill_assigns_every_existing_family_to_al_breem_without_a_branch(): void
    {
        // Bare rows: SQLite rebuilds the families table inside the test
        // transaction, which it cannot do while child rows reference it.
        $active = Family::factory()->create();
        $deleted = Family::factory()->create();
        $deleted->delete();

        // Back to the pre-slice schema: families without clan columns.
        $migration = require database_path('migrations/2026_10_01_090001_add_clan_and_branch_to_families.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('families', 'clan_id'));
        $before = DB::table('families')->orderBy('id')->get(['id', 'family_code', 'registration_date', 'status', 'deleted_at']);

        $migration->up();

        $after = DB::table('families')->orderBy('id')->get();
        $this->assertCount(2, $after);
        foreach ($after as $i => $row) {
            $this->assertSame($this->alBreem->id, (int) $row->clan_id);
            $this->assertNull($row->branch_id);
            $this->assertSame($before[$i]->family_code, $row->family_code);
            $this->assertSame($before[$i]->registration_date, $row->registration_date);
            $this->assertSame($before[$i]->status, $row->status);
            $this->assertSame($before[$i]->deleted_at, $row->deleted_at);
        }
        $this->assertSame(1, Clan::where('code', Clan::AL_BREEM)->count());
        $this->assertSame(0, Branch::whereHas('families')->count());
        $this->assertSame([$active->id, $deleted->id], $after->pluck('id')->all());
    }
}
