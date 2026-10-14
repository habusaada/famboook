<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Models\Branch;
use App\Models\Clan;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.6a: the Coordinator form reference data — the PERSON_ADD
 * relationship options and the FAMILY_ADD placements in the CURRENT scope.
 * Boundaries, permission, throttle, every scope level, unions, revocation,
 * inactive structure, privacy, and that every offered placement is exactly
 * one the submission accepts. Synthetic data only.
 */
class CoordinatorReferenceApiTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/coordinator/requests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        config(['change_requests.coordinator_limits' => ['submit_user_minute' => 100, 'submit_user_hour' => 100, 'action_user_minute' => 100, 'read_user_minute' => 100]]);
    }

    private function placements(?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->coordinatorUser)->fresh())->getJson(self::URI.'/placements')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    /** @return array<string, array{branchless: bool, branches: list<string>}> clan code => offer */
    private function offers(?User $as = null): array
    {
        $offers = [];
        foreach ($this->placements($as)->json('data') as $option) {
            $offers[$option['clan']['code']] = ['branchless' => $option['branchless_allowed'], 'branches' => array_column($option['branches'], 'code')];
        }

        return $offers;
    }

    /** Every offered placement is accepted by coversPlacement(), and nothing else in these Clans is. */
    private function assertOffersMatchTheSubmissionRule(User $user, array $clans): void
    {
        $scopes = app(CoordinatorScopes::class);
        $context = $scopes->context($user->fresh());
        $offers = $this->offers($user);
        foreach ($clans as $clan) {
            $offer = $offers[$clan->code] ?? ['branchless' => false, 'branches' => []];
            $this->assertSame($scopes->coversPlacement($context, $clan, null), $offer['branchless'], "{$clan->code} branchless");
            foreach (Branch::where('clan_id', $clan->id)->get() as $branch) {
                $this->assertSame($scopes->coversPlacement($context, $clan, $branch), in_array($branch->code, $offer['branches'], true), "{$clan->code}/{$branch->code}");
            }
        }
    }

    // ------------------------------------------------------------ boundaries

    public function test_both_routes_carry_the_boundary_permission_and_throttle(): void
    {
        $found = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array($route->uri(), ['api/v1/family/coordinator/requests/relationship-types', 'api/v1/family/coordinator/requests/placements'], true)) {
                continue;
            }
            $found++;
            $middleware = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'family.side', 'coordinator.space', 'can:coordinator-request.submit', 'throttle:coordinator-request-read'] as $required) {
                $this->assertContains($required, $middleware, $route->uri());
            }
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame([], $route->signatureParameters(['subClass' => Model::class]));
        }
        $this->assertSame(2, $found);
    }

    public function test_only_a_coordinator_with_the_submit_permission_reads_them(): void
    {
        foreach (['/relationship-types', '/placements'] as $path) {
            $this->json('GET', self::URI.$path)->assertUnauthorized();
        }
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
        $unscoped = $this->coordinator('511111110');
        foreach (['/relationship-types', '/placements'] as $path) {
            $this->actingAs($staff)->getJson(self::URI.$path)->assertForbidden();
            $this->actingAs($this->household->user->fresh())->getJson(self::URI.$path)->assertForbidden();
            $this->actingAs($unscoped->fresh())->getJson(self::URI.$path)->assertForbidden();
        }
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-request.submit');
        $this->actingAs($this->coordinatorUser->fresh())->getJson(self::URI.'/relationship-types')->assertForbidden();
        $this->actingAs($this->coordinatorUser->fresh())->getJson(self::URI.'/placements')->assertForbidden();
    }

    public function test_both_routes_are_throttled_per_user(): void
    {
        config(['change_requests.coordinator_limits.read_user_minute' => 2]);
        $this->actingAs($this->coordinatorUser->fresh());
        $this->getJson(self::URI.'/relationship-types')->assertOk();
        $this->getJson(self::URI.'/placements')->assertOk();
        $this->getJson(self::URI.'/placements')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->getJson(self::URI.'/relationship-types')->assertStatus(429);

        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->actingAs($colleague->fresh())->getJson(self::URI.'/placements')->assertOk();
    }

    // ---------------------------------------------------- relationship options

    public function test_relationship_options_are_the_active_non_head_types_by_code_and_name(): void
    {
        RelationshipType::where('code', 'MOTHER')->update(['is_active' => false]);
        $response = $this->actingAs($this->coordinatorUser->fresh())->getJson(self::URI.'/relationship-types')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $expected = RelationshipType::where('is_active', true)->where('code', '!=', 'HEAD')->orderBy('sort_order')->orderBy('code')
            ->get(['code', 'name'])->map(fn ($t) => ['code' => $t->code, 'name' => $t->name])->all();
        $this->assertSame($expected, $response->json('data'));
        $codes = array_column($response->json('data'), 'code');
        $this->assertNotContains('HEAD', $codes);
        $this->assertNotContains('MOTHER', $codes);
        $this->assertContains('DAUGHTER', $codes);
        foreach ($response->json('data') as $option) {
            $this->assertSame(['code', 'name'], array_keys($option));
        }
        // The same definition as the household's own add-member form.
        $this->assertSame($response->json('data'), $this->actingAs($this->household->user->fresh())
            ->getJson('/api/v1/family/change-requests/relationship-types')->assertOk()->json('data'));
    }

    // -------------------------------------------------------------- placements

    public function test_a_clan_scope_offers_the_clan_without_a_branch_and_all_its_active_branches(): void
    {
        $group = $this->group($this->scopeClan);
        $this->branch($this->scopeClan, $group, 'BR_G');
        $this->branch($this->scopeClan, null, 'BR_P');
        $this->branch($this->scopeClan, null, 'BR_OFF', active: false);
        $this->branch($this->scopeClan, $this->group($this->scopeClan, active: false), 'BR_DEAD_GROUP');
        $this->branch($this->clan('OTHER_CLAN'), null, 'BR_X');

        $data = $this->placements()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(['code' => 'SCOPE_CLAN', 'name' => $this->scopeClan->name], $data[0]['clan']);
        $this->assertTrue($data[0]['branchless_allowed']);
        $this->assertEqualsCanonicalizing(['BR_G', 'BR_P'], array_column($data[0]['branches'], 'code'));
        $byCode = array_column($data[0]['branches'], null, 'code');
        $this->assertSame(['code' => $group->code, 'name' => $group->name], $byCode['BR_G']['group']);
        $this->assertNull($byCode['BR_P']['group']);
        $this->assertSame(['code', 'name', 'group'], array_keys($byCode['BR_G']));
        $this->assertOffersMatchTheSubmissionRule($this->coordinatorUser, [$this->scopeClan, $this->clan('OTHER_CLAN')]);
    }

    public function test_group_and_multiple_branch_scopes_offer_their_branches_only_never_branchless(): void
    {
        $group = $this->group($this->scopeClan);
        $this->branch($this->scopeClan, $group, 'BR_G1');
        $this->branch($this->scopeClan, $group, 'BR_G2');
        $first = $this->branch($this->scopeClan, null, 'BR_1');
        $other = $this->clan('OTHER_CLAN');
        $foreign = $this->branch($other, null, 'BR_X');
        $this->branch($other, null, 'BR_Y');
        $this->branch($this->scopeClan, null, 'BR_UNASSIGNED');

        $groupCoordinator = $this->coordinator('511111110');
        $this->assign($groupCoordinator, $group);
        $this->assertSame(['SCOPE_CLAN' => ['branchless' => false, 'branches' => ['BR_G1', 'BR_G2']]], $this->offers($groupCoordinator));

        // Multiple BRANCH assignments, across two Clans: their union, per Clan.
        $branchCoordinator = $this->coordinator('611111110');
        $this->assign($branchCoordinator, $first);
        $this->assign($branchCoordinator, $foreign);
        $offers = $this->offers($branchCoordinator);
        $this->assertSame(['branchless' => false, 'branches' => ['BR_1']], $offers['SCOPE_CLAN']);
        $this->assertSame(['branchless' => false, 'branches' => ['BR_X']], $offers['OTHER_CLAN']);
        $this->assertCount(2, $offers);

        // A union of CLAN and BRANCH levels: the wider one wins in its Clan.
        $this->assign($branchCoordinator, $other);
        $this->assertSame(['branchless' => true, 'branches' => ['BR_X', 'BR_Y']], $this->offers($branchCoordinator)['OTHER_CLAN']);

        foreach ([$groupCoordinator, $branchCoordinator] as $user) {
            $this->assertOffersMatchTheSubmissionRule($user, [$this->scopeClan, $other]);
        }
    }

    public function test_revoked_assignments_and_inactive_structure_offer_nothing(): void
    {
        $other = $this->clan('OTHER_CLAN');
        $branch = $this->branch($other, null, 'BR_X');
        $this->assign($this->coordinatorUser, $branch, revoked: true);
        $this->assertSame(['SCOPE_CLAN'], array_keys($this->offers()));

        // A group-scoped Coordinator whose Group becomes inactive.
        $group = $this->group($other);
        $this->branch($other, $group, 'BR_G');
        $groupCoordinator = $this->coordinator('511111110');
        $this->assign($groupCoordinator, $group);
        $this->assign($groupCoordinator, $this->scopeClan);
        $this->assertArrayHasKey('OTHER_CLAN', $this->offers($groupCoordinator));
        $group->forceFill(['is_active' => false])->save();
        $this->assertSame(['SCOPE_CLAN'], array_keys($this->offers($groupCoordinator)));

        // An inactive Clan is never offered, whatever the assignment.
        $this->assign($this->coordinatorUser, $other);
        $this->assertArrayHasKey('OTHER_CLAN', $this->offers());
        $other->forceFill(['is_active' => false])->save();
        $this->assertSame(['SCOPE_CLAN'], array_keys($this->offers()));
        $this->assertOffersMatchTheSubmissionRule($this->coordinatorUser, [$this->scopeClan, $other]);
    }

    public function test_the_placement_response_carries_no_ids_and_nothing_outside_the_scope(): void
    {
        $this->branch($this->scopeClan, $this->group($this->scopeClan), 'BR_G');
        $hidden = $this->clan('HIDDEN_CLAN');
        $this->branch($hidden, null, 'BR_HIDDEN');
        $response = $this->placements();
        $json = $response->getContent();

        $this->assertStringNotContainsString('HIDDEN', $json);
        foreach (['"id"', '"clan_id"', '"branch_id"', '"branch_group_id"', '"uuid"', '"assignment', '"scope_type"', '"is_active"', '"sort_order"'] as $key) {
            $this->assertStringNotContainsString($key, $json, $key);
        }
        foreach ($response->json('data') as $option) {
            $this->assertSame(['clan', 'branchless_allowed', 'branches'], array_keys($option));
            $this->assertSame(['code', 'name'], array_keys($option['clan']));
        }
    }

    public function test_an_offered_placement_is_accepted_by_the_submission(): void
    {
        $this->branch($this->scopeClan, null, 'BR_P');
        $option = $this->placements()->json('data.0');

        foreach ([null, $option['branches'][0]['code']] as $i => $branchCode) {
            $this->actingAs($this->coordinatorUser->fresh())->postJson(self::URI, [
                'type' => 'REGISTER_FAMILY', 'client_reference' => (string) Str::uuid(),
                'placement' => ['clan_code' => $option['clan']['code'], 'branch_code' => $branchCode],
                'data' => ['head' => ['full_name' => 'رب أسرة '.$i]],
            ])->assertCreated();
        }
    }
}
