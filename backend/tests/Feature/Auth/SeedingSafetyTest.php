<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AUTH-ADR-057: seeding never creates an account (no known password); the
 * first SUPER_ADMIN is created interactively.
 */
class SeedingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_reference_data_but_no_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertDatabaseHas('roles', ['name' => 'SUPER_ADMIN']);
    }

    public function test_create_super_admin_command_creates_an_active_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('famboook:create-super-admin')
            ->expectsQuestion('Name', 'مدير تجريبي')
            ->expectsQuestion('Email', ' Admin@Example.test ')
            ->expectsQuestion('Password (min 10 characters)', 'synthetic-pass-123')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.test')->sole();
        $this->assertTrue($user->is_active);
        $this->assertSame(['SUPER_ADMIN'], $user->getRoleNames()->all());
        $this->assertTrue(Hash::check('synthetic-pass-123', $user->password));

        $this->artisan('famboook:create-super-admin')
            ->expectsQuestion('Name', 'آخر')
            ->expectsQuestion('Email', 'short@example.test')
            ->expectsQuestion('Password (min 10 characters)', 'short')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'short@example.test']);
    }
}
