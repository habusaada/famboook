<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1H: the scoped Coordinator family list and summary (docs/06 §22b,
 * coordinator-family.view-summary) — IDOR and privacy. Every Family is
 * reached only through the authorized query; the projection is an explicit
 * allow-list. Synthetic data only.
 */
class CoordinatorFamiliesTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const LIST = '/api/v1/family/coordinator/families';

    private const KEYS = ['family_code', 'clan_name', 'branch_group_name', 'branch_name', 'head_name', 'active_member_count'];

    private const NOT_FOUND = ['message' => 'الأسرة غير متاحة.', 'code' => 'FAMILY_NOT_FOUND'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    private function codes(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('family_code')->sort()->values()->all();
    }

    private function detail(User $as, string $code): TestResponse
    {
        return $this->fetch($as, self::LIST.'/'.$code);
    }

    // ------------------------------------------------------------ projection

    public function test_the_list_and_the_summary_carry_exactly_the_approved_fields(): void
    {
        $clan = $this->clan();
        $group = $this->group($clan, 'GRP_A');
        $branch = $this->branch($clan, $group, 'BR_A');
        $family = $this->familyIn($clan, $branch, members: 3);
        // An ended membership does not count.
        DB::table('family_memberships')->insert([
            'family_id' => $family->id, 'person_id' => Person::factory()->create()->id, 'is_household_head' => false,
            'is_active' => false, 'started_at' => now()->subYear()->toDateString(), 'ended_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->coordinator();
        $this->assign($user, $branch);

        $expected = [
            'family_code' => $family->family_code,
            'clan_name' => $clan->name,
            'branch_group_name' => $group->name,
            'branch_name' => $branch->name,
            'head_name' => 'رب أسرة '.$family->family_code,
            'active_member_count' => 3,
        ];

        $list = $this->fetch($user, self::LIST)->assertOk();
        $this->assertSame([$expected], $list->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1], $list->json('meta'));
        $this->assertStringContainsString('no-store', (string) $list->headers->get('Cache-Control'));

        $this->detail($user, $family->family_code)->assertOk()->assertExactJson(['data' => $expected]);
    }

    public function test_nullable_hierarchy_levels_stay_null(): void
    {
        $clan = $this->clan();
        $unbranched = $this->familyIn($clan);
        $ungrouped = $this->familyIn($clan, $this->branch($clan));
        $user = $this->coordinator();
        $this->assign($user, $clan);

        $rows = collect($this->fetch($user, self::LIST)->json('data'))->keyBy('family_code');

        $this->assertNull($rows[$unbranched->family_code]['branch_name']);
        $this->assertNull($rows[$unbranched->family_code]['branch_group_name']);
        $this->assertNotNull($rows[$ungrouped->family_code]['branch_name']);
        $this->assertNull($rows[$ungrouped->family_code]['branch_group_name']);
    }

    public function test_no_sensitive_value_ever_appears_in_the_list_or_the_summary(): void
    {
        $clan = $this->clan();
        $branch = $this->branch($clan);
        $family = $this->familyIn($clan, $branch, members: 2, attributes: ['notes' => 'ملاحظة سرية للأسرة', 'paper_form_no' => 'PAPER-7788']);
        $head = $family->householdHeadMembership()->first()->person;
        $head->forceFill([
            'national_id' => '807766554', 'mobile' => '0597766554', 'alternate_mobile' => '0568877665',
            'notes' => 'ملاحظة سرية للشخص', 'birth_date' => '1980-05-17',
        ])->saveQuietly();
        FamilyResidence::factory()->create(['family_id' => $family->id, 'address_text' => 'عنوان سكن سري', 'city' => 'مدينة سرية']);
        PersonHealthRecord::factory()->create(['person_id' => $head->id, 'condition_name' => 'حالة صحية سرية', 'details' => 'تفاصيل صحية سرية']);
        if ($category = DB::table('need_categories')->value('id')) {
            DB::table('family_needs')->insert([
                'uuid' => (string) Str::uuid(), 'family_id' => $family->id, 'need_category_id' => $category,
                'title' => 'احتياج سري', 'priority' => 'HIGH', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $user = $this->coordinator();
        $this->assign($user, $branch);

        $bodies = [
            $this->fetch($user, self::LIST)->assertOk()->getContent(),
            $this->detail($user, $family->family_code)->assertOk()->getContent(),
        ];

        foreach ($bodies as $body) {
            foreach ([
                '807766554', '766554', '0597766554', '0568877665', 'ملاحظة سرية', 'PAPER-7788', '1980-05-17',
                'عنوان سكن سري', 'مدينة سرية', 'حالة صحية سرية', 'تفاصيل صحية سرية', 'احتياج سري',
                $head->person_code, '"id"', 'national', 'mobile', 'residence', 'health', 'disab', 'need', 'assist',
                'note', 'document', 'account', 'activat', 'login', 'status', 'email', 'person_id', 'user',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "leaked: {$forbidden}");
            }
        }
        $this->assertSame(self::KEYS, array_keys(json_decode($bodies[0], true)['data'][0]));
        $this->assertSame(self::KEYS, array_keys(json_decode($bodies[1], true)['data']));
    }

    // -------------------------------------------------------- who may read it

    public function test_a_family_user_without_the_role_cannot_read_summaries_and_keeps_its_household(): void
    {
        $clan = $this->clan();
        $family = $this->familyIn($clan);
        $head = $this->activatedHead('111111111');
        $this->assign($head['user'], $clan);

        $this->fetch($head['user'], self::LIST)->assertForbidden();
        $this->detail($head['user'], $family->family_code)->assertForbidden();
        $this->fetch($head['user'], '/api/v1/family/me')->assertOk()->assertJsonPath('user.context.family.code', $head['family']->family_code);
    }

    public function test_a_coordinator_keeps_its_own_household_context_unchanged(): void
    {
        $clan = $this->clan('OTHER_CLAN');
        $head = $this->activatedHead('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $this->assign($head['user'], $clan);
        $this->familyIn($clan);

        // /me still describes its OWN Family, never an assigned one.
        $this->fetch($head['user'], '/api/v1/family/me')->assertOk()
            ->assertJsonPath('user.context.available', true)
            ->assertJsonPath('user.context.family.code', $head['family']->family_code)
            ->assertJsonPath('user.coordinator_space', true);
    }

    public function test_the_summary_permission_is_required_in_addition_to_the_space(): void
    {
        $clan = $this->clan();
        $family = $this->familyIn($clan);
        $user = $this->coordinator();
        $this->assign($user, $clan);
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-family.view-summary');

        $this->fetch($user, '/api/v1/family/coordinator/context')->assertOk();
        $this->fetch($user, self::LIST)->assertForbidden();
        $this->detail($user, $family->family_code)->assertForbidden();
    }

    /** @return array<string, array{0: string}> */
    public static function refusedAccounts(): array
    {
        return [
            'staff session' => ['staff'],
            'family user without coordinator' => ['family-user'],
            'coordinator only (invalid account)' => ['coordinator-only'],
            'role without assignment' => ['no-assignment'],
            'assignment without role' => ['no-role'],
            'coordinator who lost eligibility' => ['ineligible'],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_refused_accounts_reach_no_family(string $case): void
    {
        $clan = $this->clan();
        $family = $this->familyIn($clan);
        $user = match ($case) {
            'staff' => tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN')),
            'family-user' => $this->activatedHead('111111111')['user'],
            'coordinator-only' => $this->familyUser(['COORDINATOR']),
            'no-assignment' => $this->coordinator(),
            'no-role' => $this->coordinator(roles: ['FAMILY_USER']),
            'ineligible' => (function () {
                $head = $this->activatedHead('111111111', ['FAMILY_USER', 'COORDINATOR']);
                $head['family']->delete();

                return $head['user'];
            })(),
        };
        if ($case !== 'no-assignment') {
            $this->assign($user, $clan);
        }
        if ($case === 'staff') {
            $user->givePermissionTo(['coordinator-space.access', 'coordinator-family.view-summary']);
        }

        $this->fetch($user, self::LIST)->assertForbidden();
        $this->detail($user, $family->family_code)->assertForbidden();
    }

    // ------------------------------------------------------------------- IDOR

    public function test_each_scope_level_and_their_union_are_honoured(): void
    {
        $clanA = $this->clan('CLAN_A');
        $clanB = $this->clan('CLAN_B');
        $group = $this->group($clanB);
        $inGroup = $this->branch($clanB, $group);
        $single = $this->branch($clanB);
        $outside = $this->branch($clanB);
        $fClan = $this->familyIn($clanA);
        $fGroup = $this->familyIn($clanB, $inGroup);
        $fBranch = $this->familyIn($clanB, $single);
        $fOutside = $this->familyIn($clanB, $outside);
        $user = $this->coordinator();
        $this->assign($user, $clanA);
        $this->assign($user, $group);
        $this->assign($user, $single);

        $this->assertSame(
            collect([$fClan, $fGroup, $fBranch])->pluck('family_code')->sort()->values()->all(),
            $this->codes($this->fetch($user, self::LIST)),
        );
        foreach ([$fClan, $fGroup, $fBranch] as $allowed) {
            $this->detail($user, $allowed->family_code)->assertOk();
        }
        $this->detail($user, $fOutside->family_code)->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->fetch($user, '/api/v1/family/coordinator/context')->assertJsonPath('data.family_count', 3);
    }

    public function test_out_of_scope_and_nonexistent_families_are_the_same_404(): void
    {
        $clan = $this->clan();
        $mine = $this->branch($clan);
        $theirs = $this->branch($clan);
        $this->familyIn($clan, $mine);
        $outside = $this->familyIn($clan, $theirs);
        $user = $this->coordinator();
        $this->assign($user, $mine);

        $outOfScope = $this->detail($user, $outside->family_code);
        $nonexistent = $this->detail($user, 'FAM-999999');

        $outOfScope->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $nonexistent->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->assertSame($outOfScope->getContent(), $nonexistent->getContent());
    }

    public function test_internal_ids_are_not_usable_and_codes_cannot_be_tampered(): void
    {
        $clan = $this->clan();
        $mine = $this->branch($clan);
        $inScope = $this->familyIn($clan, $mine);
        $outside = $this->familyIn($clan, $this->branch($clan));
        $user = $this->coordinator();
        $this->assign($user, $mine);

        // Sequential database ids, in and out of scope, are never a key.
        foreach ([$inScope->id, $outside->id, $outside->id + 1] as $id) {
            $this->detail($user, (string) $id)->assertNotFound();
        }
        // Tampered or injected codes find nothing outside the scope.
        foreach ([$outside->family_code, strtolower($outside->family_code), $outside->family_code.'%', "' OR 1=1 --"] as $code) {
            $response = $this->fetch($user, self::LIST.'/'.rawurlencode($code));
            $this->assertContains($response->status(), [404], $code);
        }
        $this->assertSame([$inScope->family_code], $this->codes($this->fetch($user, self::LIST.'?q='.urlencode($outside->family_code))) ?: [$inScope->family_code]);
    }

    public function test_query_filters_narrow_and_never_widen(): void
    {
        $clan = $this->clan('CLAN_A');
        $other = $this->clan('CLAN_B');
        $groupA = $this->group($clan, 'GRP_A');
        $mine = $this->branch($clan, $groupA, 'BR_MINE');
        $alsoMine = $this->branch($clan, $groupA, 'BR_ALSO');
        $notMine = $this->branch($clan, null, 'BR_NOT');
        $inMine = $this->familyIn($clan, $mine);
        $inAlso = $this->familyIn($clan, $alsoMine);
        $this->familyIn($clan, $notMine);
        $this->familyIn($other, $this->branch($other, null, 'BR_OTHER'));
        $user = $this->coordinator();
        $this->assign($user, $groupA);

        $this->assertSame(collect([$inMine, $inAlso])->pluck('family_code')->sort()->values()->all(), $this->codes($this->fetch($user, self::LIST)));
        $this->assertSame([$inMine->family_code], $this->codes($this->fetch($user, self::LIST.'?branch=BR_MINE')));
        // Filters naming structure outside the scope match nothing — they never add.
        foreach (['?branch=BR_NOT', '?clan=CLAN_B', '?branch=BR_OTHER', '?branch_group=NOPE', '?clan=CLAN_B&branch=BR_OTHER'] as $query) {
            $this->assertSame([], $this->codes($this->fetch($user, self::LIST.$query)), $query);
        }
        $this->assertSame(2, count($this->codes($this->fetch($user, self::LIST.'?clan=CLAN_A&branch_group=GRP_A'))));
    }

    public function test_search_by_code_prefix_and_head_name_stays_inside_the_scope(): void
    {
        $clan = $this->clan();
        $mine = $this->branch($clan);
        $a = $this->familyIn($clan, $mine, attributes: ['family_code' => 'FAM-100001']);
        $b = $this->familyIn($clan, $mine, attributes: ['family_code' => 'FAM-200002']);
        $outside = $this->familyIn($clan, $this->branch($clan), attributes: ['family_code' => 'FAM-100003']);
        $user = $this->coordinator();
        $this->assign($user, $mine);

        $this->assertSame([$a->family_code], $this->codes($this->fetch($user, self::LIST.'?q=fam-1')));
        $this->assertSame([$b->family_code], $this->codes($this->fetch($user, self::LIST.'?q='.urlencode('رب أسرة FAM-200002'))));
        $this->assertSame([], $this->codes($this->fetch($user, self::LIST.'?q=FAM-100003')));
        $this->assertSame([], $this->codes($this->fetch($user, self::LIST.'?q='.urlencode('رب أسرة '.$outside->family_code))));
        // Wildcards are literal.
        $this->assertSame([], $this->codes($this->fetch($user, self::LIST.'?q=%25')));
        $this->fetch($user, self::LIST.'?q='.str_repeat('x', 101))->assertStatus(422);
    }

    public function test_the_list_is_paginated_on_the_server_by_25(): void
    {
        $clan = $this->clan();
        $user = $this->coordinator();
        $this->assign($user, $clan);
        for ($i = 0; $i < 27; $i++) {
            $this->familyIn($clan);
        }
        // The coordinator's own Family is in this Clan too.
        $total = Family::query()->where('clan_id', $clan->id)->count();

        $first = $this->fetch($user, self::LIST)->assertOk();
        $second = $this->fetch($user, self::LIST.'?page=2')->assertOk();

        $this->assertCount(25, $first->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => $total], $first->json('meta'));
        $this->assertCount($total - 25, $second->json('data'));
        $this->assertSame([], array_intersect(collect($first->json('data'))->pluck('family_code')->all(), collect($second->json('data'))->pluck('family_code')->all()));
    }

    public function test_a_revoked_or_inactive_scope_and_a_moved_family_change_access_on_the_next_request(): void
    {
        $clan = $this->clan();
        $group = $this->group($clan);
        $branchA = $this->branch($clan, $group);
        $branchB = $this->branch($clan);
        $family = $this->familyIn($clan, $branchA);
        $user = $this->coordinator();
        $byBranch = $this->assign($user, $branchA);
        $this->detail($user, $family->family_code)->assertOk();

        // The Family moves out of the Branch.
        $family->forceFill(['branch_id' => $branchB->id])->save();
        $this->detail($user, $family->family_code)->assertNotFound();
        $family->forceFill(['branch_id' => $branchA->id])->save();
        $this->detail($user, $family->family_code)->assertOk();

        // The Branch moves out of a Group assignment.
        $byBranch->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'SCOPE_CHANGED'])->save();
        $this->assign($user, $group);
        $this->detail($user, $family->family_code)->assertOk();
        $branchA->forceFill(['branch_group_id' => null])->save();
        // The Group assignment is still effective, but this Branch is no longer in it.
        $this->detail($user, $family->family_code)->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->assertNotContains($family->family_code, $this->codes($this->fetch($user, self::LIST)));
    }

    public function test_an_inactive_target_closes_the_family(): void
    {
        $clan = $this->clan();
        $branch = $this->branch($clan);
        $other = $this->branch($clan);
        $family = $this->familyIn($clan, $branch);
        $user = $this->coordinator();
        $this->assign($user, $branch);
        $this->assign($user, $other);

        $branch->forceFill(['is_active' => false])->save();

        $this->detail($user, $family->family_code)->assertNotFound()->assertExactJson(self::NOT_FOUND);
    }

    public function test_no_coordinator_family_route_binds_a_model(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/family/coordinator/families')) {
                $this->assertStringNotContainsString('{family}', $route->uri());
                $this->assertContains('can:coordinator-family.view-summary', $route->gatherMiddleware());
                $this->assertSame([], $route->signatureParameters(['subClass' => Model::class]));
            }
        }
    }
}
