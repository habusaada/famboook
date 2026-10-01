<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * famboook:verify-permissions (docs/08 §7): the deployment gate that the
 * stored roles equal the canonical RolePermissionSeeder baseline.
 */
class VerifyPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_after_seeding_the_canonical_baseline(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('Permission verification passed')
            ->assertSuccessful();
    }

    public function test_fails_when_a_stale_grant_remains(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // The exact drift the Pilot Gate found in an old database.
        Role::findByName('DATA_ENTRY', 'web')->givePermissionTo('family-membership.end');

        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: DATA_ENTRY must NOT have family-membership.end')
            ->assertFailed();

        // Re-running the seeder repairs it.
        $this->seed(RolePermissionSeeder::class);
        $this->artisan('famboook:verify-permissions')->assertSuccessful();
    }

    public function test_fails_when_an_expected_grant_is_missing_or_nothing_was_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Role::findByName('ADMINISTRATOR', 'web')->revokePermissionTo('person.national-id.update');

        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: ADMINISTRATOR must have person.national-id.update')
            ->assertFailed();

        $this->refreshDatabaseWithoutSeed();
        $this->artisan('famboook:verify-permissions')->assertFailed();
    }

    public function test_the_apply_gate_closed_means_no_role_holds_import_apply(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('Import Apply gate: DISABLED')
            ->assertSuccessful();

        // A stale SUPER_ADMIN grant (e.g. seeded while the gate was open) fails.
        Role::findByName('SUPER_ADMIN', 'web')->givePermissionTo('import.apply');
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: SUPER_ADMIN must NOT have import.apply (Apply gate is disabled)')
            ->assertFailed();
    }

    public function test_the_apply_gate_open_requires_super_admin_and_only_super_admin(): void
    {
        // Gate opened but the seeder not re-run: SUPER_ADMIN lacks it → fail.
        $this->seed(RolePermissionSeeder::class);
        config(['import.apply_enabled' => true]);
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: SUPER_ADMIN must have import.apply (Apply gate is enabled)')
            ->assertFailed();

        $this->seed(RolePermissionSeeder::class);
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('Import Apply gate: ENABLED (SUPER_ADMIN only)')
            ->assertSuccessful();

        Role::findByName('ADMINISTRATOR', 'web')->givePermissionTo('import.apply');
        $this->artisan('famboook:verify-permissions')
            ->expectsOutputToContain('CRITICAL: ADMINISTRATOR must NOT have import.apply')
            ->assertFailed();
    }

    public function test_import_apply_is_never_granted_directly_to_a_user(): void
    {
        foreach ([false, true] as $enabled) {
            config(['import.apply_enabled' => $enabled]);
            $this->seed(RolePermissionSeeder::class);
            $user = User::factory()->create();
            $user->givePermissionTo('import.apply');

            $this->artisan('famboook:verify-permissions')
                ->expectsOutputToContain('CRITICAL: import.apply is granted directly to a model')
                ->assertFailed();
            $user->revokePermissionTo('import.apply');
        }
        $this->artisan('famboook:verify-permissions')->assertSuccessful();
    }

    public function test_never_prints_user_data(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['name' => 'مستخدم سري', 'email' => 'private.person@example.test']);
        $user->assignRole('DATA_ENTRY');

        Artisan::call('famboook:verify-permissions');
        $output = Artisan::output();

        $this->assertStringNotContainsString('private.person@example.test', $output);
        $this->assertStringNotContainsString('مستخدم سري', $output);
    }

    private function refreshDatabaseWithoutSeed(): void
    {
        \Illuminate\Support\Facades\DB::table('role_has_permissions')->delete();
        \Illuminate\Support\Facades\DB::table('model_has_roles')->delete();
        Role::query()->delete();
    }
}
