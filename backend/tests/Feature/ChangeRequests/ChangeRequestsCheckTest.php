<?php

namespace Tests\Feature\ChangeRequests;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.7c: famboook:change-requests-check reports the coordinator channel,
 * the coordinator-request permissions and the CO-02 migrations — counts and
 * YES/NO only, never an allowlisted id or name — while the family lines keep
 * their PWA-6.1b contract (FamilySubmissionGateTest). Read-only. Synthetic
 * data only.
 */
class ChangeRequestsCheckTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        config([
            'change_requests.family_submission_mode' => 'OFF',
            'change_requests.pilot_family_ids' => '',
            'change_requests.legacy_family_submission_enabled' => null,
            'change_requests.coordinator_submission_mode' => 'OFF',
            'change_requests.coordinator_pilot_user_ids' => '',
        ]);
    }

    private function coordinatorMode(string $mode, string $ids = ''): void
    {
        config(['change_requests.coordinator_submission_mode' => $mode, 'change_requests.coordinator_pilot_user_ids' => $ids]);
    }

    public function test_the_release_default_is_off_everywhere_with_no_warnings(): void
    {
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Family submission mode (effective): OFF')
            ->expectsOutputToContain('Pilot allowlist: empty')
            ->expectsOutputToContain('Legacy CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED set: NO')
            ->expectsOutputToContain('Coordinator submission mode (effective): OFF')
            ->expectsOutputToContain('Coordinator pilot allowlist: empty')
            ->expectsOutputToContain('Coordinator request permissions: OK (4 of 4, COORDINATOR only)')
            ->expectsOutputToContain('Migration 2026_10_19_090000_add_family_id_to_auth_security_events: applied')
            ->expectsOutputToContain('Migration 2026_10_20_090000_enforce_unique_person_national_id: applied')
            ->expectsOutputToContain('Migration 2026_10_21_090000_add_coordinator_change_requests: applied')
            ->expectsOutputToContain('Change Request submission: no warnings.')
            ->assertExitCode(0);
    }

    public function test_an_invalid_coordinator_mode_fails_closed(): void
    {
        $this->coordinatorMode('yes please');
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator submission mode (effective): OFF')
            ->expectsOutputToContain('WARN: CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE is missing or invalid — treated as OFF.')
            ->assertExitCode(1);
    }

    public function test_a_malformed_coordinator_allowlist_fails_closed_and_prints_no_id(): void
    {
        $coordinator = $this->coordinator();
        $this->coordinatorMode('PILOT', $coordinator->id.',abc');
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator submission mode (effective): PILOT')
            ->expectsOutputToContain('Coordinator pilot allowlist: INVALID (no Coordinator may submit in PILOT)')
            ->expectsOutputToContain('WARN: CHANGE_REQUESTS_COORDINATOR_PILOT_USER_IDS is malformed')
            ->expectsOutputToContain('WARN: Coordinator PILOT mode with no eligible allowlisted Coordinator: nobody can submit.')
            ->doesntExpectOutputToContain($coordinator->id.',')
            ->assertExitCode(1);
    }

    public function test_the_allowlist_is_counted_never_listed(): void
    {
        $coordinator = $this->coordinator();
        $household = $this->activatedHead('223456789')['user'];
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('REVIEWER'));

        $this->coordinatorMode('PILOT', implode(',', [$coordinator->id, $household->id, $staff->id, 999999]));
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator pilot allowlist: 4 id(s), 1 eligible Coordinator account(s)')
            ->expectsOutputToContain('WARN: Some coordinator allowlisted ids are not eligible Coordinator accounts.')
            ->doesntExpectOutputToContain((string) $coordinator->id.',')
            ->doesntExpectOutputToContain($coordinator->name)
            ->assertExitCode(1);

        $this->coordinatorMode('PILOT', (string) $coordinator->id);
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator pilot allowlist: 1 id(s), 1 eligible Coordinator account(s)')
            ->expectsOutputToContain('no warnings')
            ->assertExitCode(0);

        $coordinator->forceFill(['is_active' => false])->save();
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator pilot allowlist: 1 id(s), 0 eligible Coordinator account(s)')
            ->expectsOutputToContain('WARN: Coordinator PILOT mode with no eligible allowlisted Coordinator: nobody can submit.')
            ->assertExitCode(1);
    }

    public function test_coordinator_permissions_must_be_coordinator_only(): void
    {
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-request.cancel');
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Coordinator request permissions: 4 of 4 seeded, 3 held by COORDINATOR, 0 other role(s) hold some')
            ->expectsOutputToContain('WARN: Coordinator request permissions are not as approved')
            ->assertExitCode(1);

        Role::findByName('COORDINATOR', 'web')->givePermissionTo('coordinator-request.cancel');
        Role::findByName('REVIEWER', 'web')->givePermissionTo('coordinator-request.view');
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('1 other role(s) hold some')
            ->assertExitCode(1);
    }

    public function test_a_pending_co02_migration_is_reported(): void
    {
        DB::table('migrations')->where('migration', '2026_10_21_090000_add_coordinator_change_requests')->delete();
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Migration 2026_10_21_090000_add_coordinator_change_requests: PENDING')
            ->expectsOutputToContain('WARN: Migration 2026_10_21_090000_add_coordinator_change_requests has not run.')
            ->assertExitCode(1);
    }

    public function test_the_command_writes_nothing(): void
    {
        $this->coordinatorMode('PILOT', '1,2');
        $before = [DB::table('users')->count(), DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), DB::table('migrations')->count()];
        $this->artisan('famboook:change-requests-check');
        $this->assertSame($before, [DB::table('users')->count(), DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), DB::table('migrations')->count()]);
    }
}
