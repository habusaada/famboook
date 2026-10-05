<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\RecordPersonDeathAction;
use App\Enums\LifeStatusVerificationMethod;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\Branch;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1F: the Family API boundary (docs/06 §22b) — `family.side`, the
 * bootstrap endpoint and logout. Synthetic data only.
 */
class FamilySideBoundaryTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ME = '/api/v1/family/me';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    public function test_a_family_user_reads_the_bootstrap_representation(): void
    {
        $head = $this->activatedHead();
        $head['user']->forceFill(['name' => 'لقطة قديمة'])->save();

        $response = $this->actingAs($head['user'])->getJson(self::ME)->assertOk();

        $response->assertExactJson(['user' => [
            // The Person's name, never the users.name snapshot.
            'display_name' => $head['person']->full_name,
            'roles' => ['FAMILY_USER'],
            'coordinator' => false,
            'coordinator_space' => false,
            'context' => [
                'available' => true,
                'family' => [
                    'code' => $head['family']->family_code,
                    'name' => $head['family']->branch?->name ?? $head['family']->clan?->name,
                ],
            ],
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_representation_never_carries_identifiers(): void
    {
        $head = $this->activatedHead('123456789');
        $this->trustedMobile($head['person'], '0591234567');

        $body = $this->actingAs($head['user'])->getJson(self::ME)->assertOk()->getContent();

        foreach (['123456789', '0591234567', $head['identity']->login_key, 'permissions', 'email', '"id"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_a_coordinator_who_is_a_family_user_is_admitted(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);

        $this->actingAs($head['user'])->getJson(self::ME)->assertOk()
            ->assertJsonPath('user.roles', ['FAMILY_USER', 'COORDINATOR'])
            ->assertJsonPath('user.coordinator', true);
    }

    public function test_every_other_kind_of_account_is_refused(): void
    {
        $accounts = [
            'coordinator only' => $this->familyUser(['COORDINATOR']),
            'mixed' => $this->familyUser(['FAMILY_USER', 'ADMINISTRATOR']),
            'role-less' => User::factory()->create(),
            'custom role' => tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findOrCreate('CUSTOM', 'web'))),
        ];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }

        foreach ($accounts as $label => $user) {
            $this->actingAs($user)->getJson(self::ME)
                ->assertForbidden()
                ->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE], $label);
        }
    }

    public function test_a_staff_permission_does_not_open_the_family_api(): void
    {
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('family-portal.access');

        $this->actingAs($staff)->getJson(self::ME)->assertForbidden();
    }

    public function test_a_guest_and_an_inactive_account_get_401(): void
    {
        $this->getJson(self::ME)->assertUnauthorized();

        $head = $this->activatedHead();
        $head['user']->forceFill(['is_active' => false])->save();

        $this->actingAs($head['user'])->getJson(self::ME)->assertUnauthorized();
    }

    public function test_a_family_account_never_enters_the_staff_api_even_with_a_staff_permission(): void
    {
        $head = $this->activatedHead();
        $head['user']->givePermissionTo(['family.view', 'person.view', 'dashboard.view-operational']);

        foreach (['/api/v1/families', '/api/v1/people', '/api/v1/dashboard', '/api/v1/me'] as $uri) {
            $this->actingAs($head['user'])->getJson($uri)
                ->assertForbidden()
                ->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        }
    }

    public function test_without_a_family_context_the_account_is_admitted_but_gets_no_family(): void
    {
        $head = $this->activatedHead();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        // The head dies: identity and context are both gone.
        app(RecordPersonDeathAction::class)->handle($head['person'], now()->toDateString(), LifeStatusVerificationMethod::IN_PERSON, $admin->id);

        $response = $this->actingAs($head['user'])->getJson(self::ME)->assertOk();

        $response->assertExactJson(['user' => [
            'display_name' => null,
            'roles' => ['FAMILY_USER'],
            'coordinator' => false,
            'coordinator_space' => false,
            'context' => ['available' => false, 'family' => null],
        ]]);
    }

    public function test_a_head_who_is_no_longer_head_keeps_a_name_but_loses_the_family(): void
    {
        $head = $this->activatedHead();
        $head['membership']->forceFill(['is_household_head' => false])->save();

        $this->actingAs($head['user'])->getJson(self::ME)->assertOk()
            ->assertJsonPath('user.display_name', $head['person']->full_name)
            ->assertJsonPath('user.context', ['available' => false, 'family' => null]);
    }

    public function test_the_family_name_is_the_branch_then_the_clan(): void
    {
        $head = $this->activatedHead();
        $branch = Branch::create(['clan_id' => $head['family']->clan_id, 'code' => 'BR_TEST', 'name' => 'فرع الاختبار', 'is_active' => true]);
        $head['family']->forceFill(['branch_id' => $branch->id])->save();

        $this->actingAs($head['user'])->getJson(self::ME)
            ->assertJsonPath('user.context.family.name', $branch->name);
    }

    public function test_logout_ends_the_session(): void
    {
        $head = $this->activatedHead();

        $this->actingAs($head['user'], 'web')->postJson('/api/v1/family/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_a_guest_cannot_call_logout(): void
    {
        $this->postJson('/api/v1/family/auth/logout')->assertUnauthorized();
    }

    public function test_family_routes_are_outside_the_staff_group_and_behind_their_own_boundary(): void
    {
        // Public by design, each behind its own feature gate (PWA-1F, PWA-1G).
        $gates = [
            'api/v1/family/auth/activation/start' => 'family.activation', 'api/v1/family/auth/activation/send' => 'family.activation',
            'api/v1/family/auth/activation/verify' => 'family.activation',
            'api/v1/family/auth/activation/resend' => 'family.activation', 'api/v1/family/auth/activation/complete' => 'family.activation',
            'api/v1/family/auth/login' => 'family.login',
            'api/v1/family/auth/password/reset/start' => 'family.password-reset', 'api/v1/family/auth/password/reset/verify' => 'family.password-reset',
            'api/v1/family/auth/password/reset/resend' => 'family.password-reset', 'api/v1/family/auth/password/reset/complete' => 'family.password-reset',
        ];
        $public = array_keys($gates);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/family/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertNotContains('staff.side', $middleware, $route->uri());
            if (in_array($route->uri(), $public, true)) {
                $this->assertNotContains('auth:sanctum', $middleware, $route->uri());
                // The gate, then a throttle: never an open public route.
                $this->assertContains($gates[$route->uri()], $middleware, $route->uri());
                $this->assertNotEmpty(array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'throttle:family-')), $route->uri());
            } elseif ($route->uri() === 'api/v1/family/auth/logout') {
                $this->assertContains('auth:sanctum', $middleware);
            } else {
                $this->assertContains('auth:sanctum', $middleware, $route->uri());
                $this->assertContains('family.side', $middleware, $route->uri());
            }
            // Family data (PWA-3A): the permission, then the resolved context.
            if (in_array($route->uri(), ['api/v1/family/household', 'api/v1/family/household/members', 'api/v1/family/household/profile'], true)) {
                $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'], $middleware);
            }
            $checked++;
        }
        $this->assertSame(18, $checked);
    }
}
