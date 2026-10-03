<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\LifeStatus;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1H: the Coordinator Space boundary (docs/06 §22b) — `coordinator.space`
 * after `family.side`, the context endpoint, and /family/me.coordinator_space,
 * which must always agree with the boundary. Synthetic data only.
 */
class CoordinatorSpaceBoundaryTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const CONTEXT = '/api/v1/family/coordinator/context';

    private const DENIED = ['message' => 'مساحة التنسيق غير متاحة لهذا الحساب.', 'code' => 'COORDINATOR_SPACE_UNAVAILABLE'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function context(User $as): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson(self::CONTEXT);
    }

    private function me(User $as): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson('/api/v1/family/me');
    }

    /** The boundary and /family/me agree, whatever the case. */
    private function assertAgreement(User $user, bool $open): void
    {
        $context = $this->context($user);
        $open ? $context->assertOk() : $context->assertStatus(403);
        $me = $this->me($user);
        if ($me->status() === 200) {
            $this->assertSame($open, $me->json('user.coordinator_space'));
        } else {
            $this->assertFalse($open);
        }
    }

    public function test_a_coordinator_with_a_scope_enters_and_sees_its_context(): void
    {
        $clan = $this->clan();
        $branch = $this->branch($clan, null, 'BR_A');
        $this->familyIn($clan, $branch);
        $this->familyIn($clan, $branch);
        $this->familyIn($clan);
        $user = $this->coordinator();
        $this->assign($user, $branch);

        $response = $this->context($user)->assertOk();

        $response->assertExactJson(['data' => [
            'scopes' => [['type' => 'BRANCH', 'code' => 'BR_A', 'name' => $branch->name, 'clan_name' => $clan->name]],
            'family_count' => 2,
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->me($user)->assertJsonPath('user.coordinator', true)->assertJsonPath('user.coordinator_space', true);
    }

    public function test_the_context_carries_no_internal_ids_actors_or_auth_data(): void
    {
        $user = $this->coordinator();
        $this->assign($user, $this->clan());

        $body = $this->context($user)->getContent();

        foreach (['"id"', 'user_id', 'clan_id', 'assigned_by', 'revoked', 'login_key', 'national', 'mobile', '111111111'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_a_family_user_without_the_role_is_refused_and_its_household_is_unchanged(): void
    {
        $head = $this->activatedHead('111111111');
        $this->assign($head['user'], $this->clan());

        $this->context($head['user'])->assertForbidden()->assertExactJson(self::DENIED);
        $this->me($head['user'])->assertOk()
            ->assertJsonPath('user.coordinator', false)
            ->assertJsonPath('user.coordinator_space', false)
            ->assertJsonPath('user.context.available', true);
    }

    public function test_the_role_without_an_assignment_is_refused(): void
    {
        $user = $this->coordinator();

        $this->context($user)->assertForbidden()->assertExactJson(self::DENIED);
        $this->me($user)->assertJsonPath('user.coordinator', true)->assertJsonPath('user.coordinator_space', false);
    }

    public function test_staff_and_invalid_accounts_never_reach_the_coordinator_boundary(): void
    {
        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        // A Staff account holding the coordinator permissions directly, with an assignment.
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('coordinator-space.access');
        $this->assign($staff, $this->clan());
        $accounts['staff with permission and assignment'] = $staff;
        $mixed = $this->coordinator('222222222');
        $mixed->assignRole('ADMINISTRATOR');
        $this->assign($mixed, $this->clan());
        $accounts['mixed coordinator'] = $mixed;

        foreach ($accounts as $label => $user) {
            $this->context($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
        $this->app['auth']->forgetGuards();
        $this->getJson(self::CONTEXT)->assertUnauthorized();
    }

    public function test_a_coordinator_never_reaches_the_staff_api(): void
    {
        $user = $this->coordinator();
        $this->assign($user, $this->clan());
        $user->givePermissionTo(['family.view', 'coordinator-scope.manage']);

        foreach (['/api/v1/families', '/api/v1/me', '/api/v1/people'] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user->fresh())->getJson($uri)->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        }
    }

    public function test_the_flag_and_the_boundary_agree_through_every_change(): void
    {
        $clan = $this->clan();
        $head = $this->activatedHead('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $user = $head['user'];
        $this->assertAgreement($user, false);

        $assignment = $this->assign($user, $clan);
        $this->assertAgreement($user, true);

        // Space permission taken from the role.
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-space.access');
        $this->assertAgreement($user, false);
        Role::findByName('COORDINATOR', 'web')->givePermissionTo('coordinator-space.access');
        $this->assertAgreement($user, true);

        // Target deactivated, then restored.
        $clan->forceFill(['is_active' => false])->save();
        $this->assertAgreement($user, false);
        $clan->forceFill(['is_active' => true])->save();
        $this->assertAgreement($user, true);

        // Lost eligibility.
        $head['membership']->forceFill(['is_household_head' => false])->save();
        $this->assertAgreement($user, false);
        $head['membership']->forceFill(['is_household_head' => true])->save();
        $this->assertAgreement($user, true);

        // Assignment revoked.
        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertAgreement($user, false);

        // New assignment, then the role removed.
        $this->assign($user, $clan);
        $this->assertAgreement($user, true);
        $user->removeRole('COORDINATOR');
        $this->assertAgreement($user, false);
    }

    public function test_a_deceased_coordinator_loses_the_space_and_the_portal(): void
    {
        $head = $this->activatedHead('111111111', ['FAMILY_USER', 'COORDINATOR']);
        $this->assign($head['user'], $this->clan());
        $head['person']->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save();

        $this->context($head['user'])->assertForbidden()->assertExactJson(self::DENIED);
        $this->me($head['user'])->assertJsonPath('user.coordinator_space', false)->assertJsonPath('user.context.available', false);
    }

    public function test_the_coordinator_routes_carry_both_boundaries(): void
    {
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/family/coordinator')) {
                $middleware = $route->gatherMiddleware();
                foreach (['auth:sanctum', 'family.side', 'coordinator.space'] as $required) {
                    $this->assertContains($required, $middleware, $route->uri());
                }
                $this->assertNotContains('staff.side', $middleware);
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $checked);
    }
}
