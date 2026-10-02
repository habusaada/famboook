<?php

namespace Tests\Feature\Auth;

use App\Actions\ManageStaffUsersAction;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PWA-1D: Staff-side and family-side accounts are disjoint (docs/06 §22b,
 * AUTH-ADR-065). Nothing depends on role order, a family-side account can
 * never enter the Staff API even holding a Staff permission, and Staff
 * administration can never touch a family-side account. Synthetic data only.
 */
class AccountSideBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-pass-123';

    /** Family-side or invalid accounts: every one is refused on the Staff side. */
    private const NOT_STAFF = [
        'FAMILY_USER' => ['FAMILY_USER'],
        'FAMILY_USER + COORDINATOR' => ['FAMILY_USER', 'COORDINATOR'],
        'COORDINATOR + FAMILY_USER' => ['COORDINATOR', 'FAMILY_USER'],
        'COORDINATOR only' => ['COORDINATOR'],
        'DATA_ENTRY + FAMILY_USER' => ['DATA_ENTRY', 'FAMILY_USER'],
        'FAMILY_USER + DATA_ENTRY' => ['FAMILY_USER', 'DATA_ENTRY'],
        'SUPER_ADMIN + COORDINATOR' => ['SUPER_ADMIN', 'COORDINATOR'],
        'ADMINISTRATOR + FAMILY_USER + COORDINATOR' => ['ADMINISTRATOR', 'FAMILY_USER', 'COORDINATOR'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @param list<string> $roles */
    private function account(array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function login(string $email)
    {
        // A first-party SPA request, so Sanctum treats it as stateful.
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD], ['Referer' => 'http://localhost:3000']);
    }

    // ------------------------------------------------------------ Staff login

    public function test_every_staff_role_still_logs_in_and_me_reports_that_role(): void
    {
        foreach (StaffRoles::ALL as $role) {
            $user = $this->account([$role], ['email' => strtolower($role).'@example.test']);

            $this->login($user->email)->assertOk()
                ->assertJsonPath('user.role', $role)
                ->assertJsonPath('user.role_label', StaffRoles::LABELS[$role]);
            $this->assertAuthenticatedAs($user, 'web');

            // A clean slate for the next account in this same test.
            Auth::guard('web')->logout();
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.role', $role);
            Auth::guard('web')->logout();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_family_side_and_mixed_accounts_cannot_use_staff_login_in_any_role_order(): void
    {
        $i = 0;
        foreach (self::NOT_STAFF as $label => $roles) {
            // A real email and the right password: only the account side fails it.
            $user = $this->account($roles, ['email' => 'account'.(++$i).'@example.test']);

            $this->login($user->email)->assertStatus(422)->assertJsonPath('errors.email.0', LoginRequest::FAILED);
            $this->assertGuest('web');
        }
    }

    public function test_the_refusal_is_the_same_generic_failure_as_a_wrong_password(): void
    {
        $this->account(['DATA_ENTRY'], ['email' => 'staff@example.test']);
        $this->account(['DATA_ENTRY', 'FAMILY_USER'], ['email' => 'mixed@example.test']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.test', 'password' => 'wrong-password-1'], ['Referer' => 'http://localhost:3000']);
        $mixed = $this->login('mixed@example.test');

        $this->assertSame($wrongPassword->status(), $mixed->status());
        $this->assertSame($wrongPassword->json(), $mixed->json());
    }

    // ------------------------------------------------------- Staff API boundary

    public function test_a_family_side_account_never_enters_the_staff_api_even_with_the_permission(): void
    {
        foreach (self::NOT_STAFF as $label => $roles) {
            $user = $this->account($roles);
            // It "somehow" holds the route's permission, directly.
            $user->givePermissionTo(['family.view', 'person.view', 'dashboard.view-operational']);
            $this->assertTrue($user->fresh()->can('family.view'), $label);

            foreach (['/api/v1/families', '/api/v1/people', '/api/v1/me', '/api/v1/dashboard/scope-options'] as $uri) {
                $this->actingAs($user->fresh())->getJson($uri)
                    ->assertStatus(403)
                    ->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
            }
        }
    }

    public function test_a_family_side_account_cannot_write_through_the_staff_api(): void
    {
        $user = $this->account(['FAMILY_USER', 'COORDINATOR']);
        $user->givePermissionTo(['family.create', 'person-mobile-trust.assist', 'person.national-id.update']);

        $this->actingAs($user)->postJson('/api/v1/families', [])->assertStatus(403)
            ->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertStatus(403);
    }

    public function test_legitimate_staff_accounts_keep_their_existing_behaviour(): void
    {
        $dataEntry = $this->account(['DATA_ENTRY']);
        $viewer = $this->account(['REPORTS_VIEWER']);

        $this->actingAs($dataEntry)->getJson('/api/v1/families')->assertOk();
        $this->actingAs($dataEntry)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.role', 'DATA_ENTRY');
        // Permissions still decide what a Staff account may do.
        $this->actingAs($viewer)->postJson('/api/v1/families', [])->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/families')->assertOk();
    }

    public function test_an_account_without_any_family_side_role_is_left_to_the_permission_checks(): void
    {
        // The middleware refuses family-side accounts; it does not replace
        // the permission checks for everything else.
        $none = $this->account([]);
        $this->actingAs($none)->getJson('/api/v1/families')->assertStatus(403);

        $none->givePermissionTo('family.view');
        $this->actingAs($none->fresh())->getJson('/api/v1/families')->assertOk();
    }

    public function test_every_authenticated_staff_route_is_behind_the_boundary(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (in_array('auth:sanctum', $middleware, true)) {
                $this->assertContains('staff.side', $middleware, $route->uri());
                $checked++;
            } else {
                // Public routes only: the health check and the Staff login.
                $this->assertContains($route->uri(), ['api/v1/health', 'api/v1/auth/login'], $route->uri());
            }
            // No Family Portal route exists yet (PWA-1G).
            $this->assertStringStartsNotWith('api/v1/family/', $route->uri());
        }
        $this->assertGreaterThan(80, $checked);
    }

    public function test_guests_still_get_401_not_the_boundary_message(): void
    {
        $this->getJson('/api/v1/families')->assertStatus(401);
    }

    // --------------------------------------------------- Staff administration

    public function test_staff_administration_can_never_manage_a_family_side_account(): void
    {
        $super = $this->account(['SUPER_ADMIN']);
        $action = app(ManageStaffUsersAction::class);

        foreach (self::NOT_STAFF as $label => $roles) {
            $target = $this->account($roles);
            $before = $target->getRoleNames()->sort()->values()->all();
            $this->assertFalse(ManageStaffUsersAction::canManage($super, $target), $label);

            foreach ([
                fn () => $action->update($super, $target, ['role' => 'DATA_ENTRY']),
                fn () => $action->update($super, $target, ['name' => 'اسم آخر']),
                fn () => $action->setActive($super, $target, false),
                fn () => $action->resetPassword($super, $target, 'another-synthetic-pass-1'),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail("Staff administration changed a family-side account ({$label}).");
                } catch (AuthorizationException) {
                }
            }

            // No role was synced away, and the account is untouched.
            $this->assertSame($before, $target->fresh()->getRoleNames()->sort()->values()->all(), $label);
            $this->assertTrue($target->fresh()->is_active);
        }
    }

    public function test_family_side_roles_are_never_assignable_from_staff_administration(): void
    {
        $super = $this->account(['SUPER_ADMIN']);
        $staff = $this->account(['DATA_ENTRY']);
        $action = app(ManageStaffUsersAction::class);

        foreach (['FAMILY_USER', 'COORDINATOR'] as $role) {
            $this->assertNotContains($role, ManageStaffUsersAction::assignableRoles($super));
            try {
                $action->update($super, $staff, ['role' => $role]);
                $this->fail("{$role} was assigned from Staff administration.");
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(['DATA_ENTRY'], $staff->fresh()->getRoleNames()->all());

        // A normal role change still works.
        $action->update($super, $staff, ['role' => 'REVIEWER']);
        $this->assertSame(['REVIEWER'], $staff->fresh()->getRoleNames()->all());
    }

    public function test_filament_lists_staff_accounts_only_and_admits_staff_side_administrators_only(): void
    {
        $admin = $this->account(['ADMINISTRATOR']);
        $staff = $this->account(['DATA_ENTRY']);
        $hidden = [];
        foreach (self::NOT_STAFF as $roles) {
            $hidden[] = $this->account($roles)->id;
        }

        $listed = UserResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($admin->id, $listed);
        $this->assertContains($staff->id, $listed);
        $this->assertSame([], array_intersect($hidden, $listed));

        $panel = Filament::getPanel('admin');
        $this->assertTrue($admin->canAccessPanel($panel));
        // A mixed account holds system-admin.access through ADMINISTRATOR and
        // is still refused: it is not a Staff-side account.
        $mixed = $this->account(['ADMINISTRATOR', 'FAMILY_USER']);
        $this->assertTrue($mixed->can('system-admin.access'));
        $this->assertFalse($mixed->canAccessPanel($panel));
        $family = $this->account(['FAMILY_USER']);
        $family->givePermissionTo('system-admin.access');
        $this->assertFalse($family->fresh()->canAccessPanel($panel));
    }

    // ------------------------------------------------------ deployment verifier

    public function test_the_verifier_accepts_valid_accounts_on_both_sides(): void
    {
        $this->account(['DATA_ENTRY']);
        $this->account(['FAMILY_USER']);
        $this->account(['FAMILY_USER', 'COORDINATOR']);

        $this->artisan('famboook:verify-permissions')->assertExitCode(0);
    }

    public function test_the_verifier_refuses_mixed_and_coordinator_only_accounts(): void
    {
        $this->account(['DATA_ENTRY', 'FAMILY_USER']);
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: 1 account(s) mix Staff-side and family-side roles')
            ->assertExitCode(1);

        $this->account(['COORDINATOR']);
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: 2 account(s) mix Staff-side and family-side roles')
            ->assertExitCode(1);
    }
}
