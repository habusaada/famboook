<?php

namespace Tests\Feature\FamilyAuth;

use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1H: the Staff API for coordinators (docs/06 §22b) — state, role,
 * scopes. Staff-side holders of coordinator-scope.view / .manage only.
 * Synthetic data only.
 */
class CoordinatorAdminApiTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private User $admin;

    private Person $person;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->admin = $this->staff('ADMINISTRATOR', 'مسؤول المنسقين');
        $head = $this->activatedHead('111111111');
        $this->person = $head['person'];
        $this->account = $head['user'];
    }

    private function staff(string $role, ?string $name = null): User
    {
        return tap(User::factory()->create($name ? ['name' => $name] : []), fn (User $u) => $u->assignRole($role));
    }

    private function show(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->getJson("/api/v1/people/{$this->person->person_code}/coordinator");
    }

    private function grant(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/people/{$this->person->person_code}/coordinator");
    }

    private function assignScope(array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/people/{$this->person->person_code}/coordinator/scopes", $body);
    }

    public function test_the_whole_lifecycle_through_the_api(): void
    {
        $clan = $this->clan();
        $group = $this->group($clan, 'GRP_A');
        $branch = $this->branch($clan, $group, 'BR_A');

        $this->show()->assertOk()->assertExactJson(['data' => [
            'person_code' => $this->person->person_code,
            'has_family_account' => true,
            'coordinator' => false,
            'coordinator_space' => false,
            'assignments' => [],
        ]]);

        $this->grant()->assertCreated()
            ->assertJsonPath('data.coordinator', true)
            ->assertJsonPath('data.coordinator_space', false);

        $this->assignScope(['scope_type' => 'BRANCH', 'clan' => 'TEST_CLAN', 'branch' => 'BR_A'])->assertCreated()
            ->assertJsonPath('data.coordinator_space', true)
            ->assertJsonPath('data.assignments.0.scope_type', 'BRANCH')
            ->assertJsonPath('data.assignments.0.branch', ['code' => 'BR_A', 'name' => $branch->name])
            ->assertJsonPath('data.assignments.0.assigned_by', 'مسؤول المنسقين')
            ->assertJsonPath('data.assignments.0.active', true);
        $this->assignScope(['scope_type' => 'BRANCH_GROUP', 'clan' => 'TEST_CLAN', 'branch_group' => 'GRP_A'])->assertCreated();
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => 'TEST_CLAN'])->assertCreated()
            ->assertJsonCount(3, 'data.assignments');

        // Revoke one scope by its public uuid.
        $uuid = CoordinatorScopeAssignment::where('scope_type', 'CLAN')->sole()->uuid;
        $this->actingAs($this->admin)->postJson("/api/v1/coordinator-scopes/{$uuid}/revoke", ['reason' => 'SCOPE_CHANGED'])->assertOk()
            ->assertJsonPath('data.id', $uuid)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.revoke_reason', 'SCOPE_CHANGED')
            ->assertJsonPath('data.revoked_by', 'مسؤول المنسقين');

        // Revoke the role: every remaining scope goes with it.
        $this->actingAs($this->admin)->postJson("/api/v1/people/{$this->person->person_code}/coordinator/revoke", ['reason' => 'ADMINISTRATIVE'])->assertOk()
            ->assertJsonPath('data.coordinator', false)
            ->assertJsonPath('data.coordinator_space', false);
        $this->assertSame(0, CoordinatorScopeAssignment::query()->active()->count());
        $this->assertSame(['FAMILY_USER'], $this->account->fresh()->getRoleNames()->all());
    }

    public function test_the_responses_carry_no_internal_ids_and_no_identifiers(): void
    {
        $this->grant();
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => $this->clan()->code]);

        $body = $this->show()->assertOk()->getContent();

        foreach (['"user_id"', '"clan_id"', '"branch_id"', '"assigned_by_id"', '111111111', 'national', 'mobile', 'email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
        $this->assertSame(
            ['id', 'scope_type', 'clan', 'branch_group', 'branch', 'active', 'assigned_at', 'assigned_by', 'revoked_at', 'revoked_by', 'revoke_reason'],
            array_keys(json_decode($body, true)['data']['assignments'][0]),
        );
    }

    public function test_refusals_are_explained_to_staff(): void
    {
        [$neverActivated] = $this->eligibleHead('999999999');
        $this->actingAs($this->admin)->postJson("/api/v1/people/{$neverActivated->person_code}/coordinator")
            ->assertStatus(422)->assertJsonPath('code', 'NO_FAMILY_ACCOUNT');

        $this->assignScope(['scope_type' => 'CLAN', 'clan' => $this->clan()->code])->assertStatus(409)->assertJsonPath('code', 'NOT_COORDINATOR');
        $this->grant()->assertCreated();
        $this->grant()->assertStatus(409)->assertJsonPath('code', 'ALREADY_COORDINATOR');

        $inactive = $this->branch($this->clan(), null, 'BR_OFF', active: false);
        $this->assignScope(['scope_type' => 'BRANCH', 'clan' => 'TEST_CLAN', 'branch' => $inactive->code])->assertStatus(422)->assertJsonPath('code', 'TARGET_INACTIVE');

        $this->assignScope(['scope_type' => 'CLAN', 'clan' => 'TEST_CLAN'])->assertCreated();
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => 'TEST_CLAN'])->assertStatus(409)->assertJsonPath('code', 'SCOPE_EXISTS');
    }

    public function test_the_scope_body_names_exactly_the_codes_of_its_level(): void
    {
        $this->grant();
        $clan = $this->clan();
        $this->branch($clan, null, 'BR_A');
        $this->clan('OTHER');

        $this->assignScope([])->assertStatus(422)->assertJsonValidationErrors(['scope_type', 'clan']);
        $this->assignScope(['scope_type' => 'TEAM', 'clan' => 'TEST_CLAN'])->assertStatus(422)->assertJsonValidationErrors('scope_type');
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('clan');
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => 'TEST_CLAN', 'branch' => 'BR_A'])->assertStatus(422)->assertJsonValidationErrors('branch');
        $this->assignScope(['scope_type' => 'BRANCH', 'clan' => 'TEST_CLAN'])->assertStatus(422)->assertJsonValidationErrors('branch');
        // A Branch code is looked up inside the named Clan only.
        $this->assignScope(['scope_type' => 'BRANCH', 'clan' => 'OTHER', 'branch' => 'BR_A'])->assertStatus(422)->assertJsonValidationErrors('branch');
        $this->assignScope(['scope_type' => 'BRANCH_GROUP', 'clan' => 'TEST_CLAN', 'branch_group' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('branch_group');

        $this->assertSame(0, CoordinatorScopeAssignment::count());
    }

    public function test_revocation_reasons_are_validated_and_role_removed_is_not_choosable(): void
    {
        $this->grant();
        $this->assignScope(['scope_type' => 'CLAN', 'clan' => $this->clan()->code]);
        $uuid = CoordinatorScopeAssignment::sole()->uuid;

        foreach ([[], ['reason' => 'ROLE_REMOVED'], ['reason' => 'WHATEVER']] as $body) {
            $this->actingAs($this->admin)->postJson("/api/v1/coordinator-scopes/{$uuid}/revoke", $body)->assertStatus(422)->assertJsonValidationErrors('reason');
            $this->actingAs($this->admin)->postJson("/api/v1/people/{$this->person->person_code}/coordinator/revoke", $body)->assertStatus(422);
        }
        $this->actingAs($this->admin)->postJson('/api/v1/coordinator-scopes/00000000-0000-0000-0000-000000000000/revoke', ['reason' => 'ADMINISTRATIVE'])->assertNotFound();
        $this->actingAs($this->admin)->postJson('/api/v1/coordinator-scopes/1/revoke', ['reason' => 'ADMINISTRATIVE'])->assertNotFound();
    }

    public function test_only_holders_of_the_permissions_may_use_the_api(): void
    {
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $staff = $this->staff($role);
            $this->show($staff)->assertForbidden();
            $this->grant($staff)->assertForbidden();
        }
        $this->show($this->staff('SUPER_ADMIN'))->assertOk();
        $this->assertFalse($this->account->fresh()->hasRole('COORDINATOR'));
    }

    public function test_a_family_side_account_never_reaches_it_even_with_the_permission(): void
    {
        $coordinator = $this->coordinator('222222222');
        $coordinator->givePermissionTo(['coordinator-scope.view', 'coordinator-scope.manage']);

        $this->show($coordinator)->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        $this->grant($coordinator)->assertForbidden();
        $this->assertFalse($this->account->fresh()->hasRole('COORDINATOR'));
    }

    public function test_every_coordinator_admin_route_is_behind_the_staff_boundary(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'coordinator') && ! str_starts_with($route->uri(), 'api/v1/family/')) {
                $middleware = $route->gatherMiddleware();
                $this->assertContains('staff.side', $middleware, $route->uri());
                $this->assertNotEmpty(array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'can:coordinator-scope.')), $route->uri());
                $checked++;
            }
        }
        $this->assertSame(5, $checked);
    }
}
