<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\User;
use App\Support\AccountSide;
use Database\Seeders\RolePermissionSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: corrupted RBAC data never moves an account across the Staff /
 * Family boundary. The side is derived from ROLES only; a permission granted
 * directly changes nothing, and mixed roles are refused on both sides. The
 * runtime middleware fails closed whatever the permission rows say.
 * Synthetic data only.
 */
class AccountSideCorruptionTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

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

    private function assertStaffRefused(User $user): void
    {
        $this->fetch($user, '/api/v1/me')->assertStatus(403);
        $this->fetch($user, '/api/v1/families')->assertStatus(403);
    }

    private function assertFamilyRefused(User $user): void
    {
        $this->fetch($user, '/api/v1/family/me')->assertStatus(403);
        $this->fetch($user, '/api/v1/family/coordinator/context')->assertStatus(403);
        $this->fetch($user, '/api/v1/family/coordinator/families')->assertStatus(403);
    }

    public function test_a_family_account_with_staff_permissions_granted_directly_stays_family(): void
    {
        $head = $this->activatedHead('123456789');
        $user = $head['user'];
        $user->givePermissionTo(['family.view', 'person.view', 'system-admin.access']);

        $this->assertSame(AccountSide::FAMILY, AccountSide::of($user->fresh()));
        $this->assertStaffRefused($user);
        $this->assertFalse($user->fresh()->canAccessPanel(app(Panel::class)));
        $this->fetch($user, '/api/v1/family/me')->assertOk();
    }

    public function test_a_staff_account_with_family_permissions_granted_directly_stays_staff(): void
    {
        $staff = User::factory()->create()->assignRole('ADMINISTRATOR');
        $staff->givePermissionTo(['family-portal.access', 'coordinator-space.access', 'coordinator-family.view-summary']);
        $this->assign($staff, $this->clan());

        $this->assertSame(AccountSide::STAFF, AccountSide::of($staff->fresh()));
        $this->assertFamilyRefused($staff);
        $this->fetch($staff, '/api/v1/me')->assertOk();
    }

    public function test_coordinator_permissions_without_the_coordinator_role_open_nothing(): void
    {
        $head = $this->activatedHead('123456789');
        $user = $head['user'];
        $user->givePermissionTo(['coordinator-space.access', 'coordinator-family.view-summary']);
        $this->assign($user, $this->clan());

        $this->fetch($user, '/api/v1/family/coordinator/context')->assertStatus(403);
        $this->fetch($user, '/api/v1/family/coordinator/families')->assertStatus(403);
        $this->fetch($user, '/api/v1/family/me')->assertOk()->assertJsonPath('user.coordinator_space', false);
    }

    public function test_staff_mixed_with_family_user_is_refused_on_both_sides(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER']);
        $user = $head['user'];
        $user->assignRole('ADMINISTRATOR');

        $this->assertSame(AccountSide::INVALID, AccountSide::of($user->fresh()));
        $this->assertStaffRefused($user);
        $this->assertFamilyRefused($user);
    }

    public function test_staff_mixed_with_coordinator_is_refused_on_both_sides(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $user = $head['user'];
        $this->assign($user, $this->clan());
        $user->assignRole('DATA_ENTRY');

        $this->assertSame(AccountSide::INVALID, AccountSide::of($user->fresh()));
        $this->assertStaffRefused($user);
        $this->assertFamilyRefused($user);

        $staffCoordinator = User::factory()->create()->assignRole(['DATA_ENTRY', 'COORDINATOR']);
        $this->assertStaffRefused($staffCoordinator);
        $this->assertFamilyRefused($staffCoordinator);
    }

    public function test_permissions_never_change_the_side(): void
    {
        $roleless = User::factory()->create();
        $roleless->givePermissionTo(['family.view', 'family-portal.access', 'coordinator-space.access']);

        $this->assertSame(AccountSide::NONE, AccountSide::of($roleless->fresh()));
        $this->assertStaffRefused($roleless);
        $this->assertFamilyRefused($roleless);
    }
}
