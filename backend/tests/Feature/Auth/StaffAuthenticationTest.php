<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Staff authentication (docs/06 §59c, AUTH-ADR-057): Sanctum session
 * login, generic failures, rate limiting, logout, /me and the central
 * inactive-account enforcement.
 */
class StaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // A first-party SPA request, so Sanctum treats it as stateful.
        $this->withHeader('Referer', 'http://localhost:3000');
    }

    private function staff(string $role = 'DATA_ENTRY', array $attributes = []): User
    {
        $user = User::factory()->create(['email' => 'staff@example.test', 'password' => self::PASSWORD, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function login(string $email, string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_valid_credentials_log_in_on_the_session_and_regenerate_it(): void
    {
        $user = $this->staff();
        $idAtLogin = null;
        Event::listen(Login::class, function () use (&$idAtLogin) {
            $idAtLogin = session()->getId();
        });

        // Email is normalized (case / surrounding spaces).
        $response = $this->login('  STAFF@example.test ');

        $response->assertOk()->assertJsonPath('user.email', 'staff@example.test')->assertJsonPath('user.role', 'DATA_ENTRY');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($idAtLogin);
        $this->assertNotSame($idAtLogin, session()->getId(), 'The session id must change after authentication.');
        // No token or credential is handed to JavaScript.
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_wrong_password_unknown_email_inactive_and_non_staff_fail_identically(): void
    {
        $this->staff();
        $this->staff('DATA_ENTRY', ['email' => 'inactive@example.test', 'is_active' => false]);
        $this->staff('FAMILY_USER', ['email' => 'family@example.test']);
        User::factory()->create(['email' => 'norole@example.test', 'password' => self::PASSWORD]);

        $attempts = [
            $this->login('staff@example.test', 'wrong-password-1'),
            $this->login('nobody@example.test'),
            $this->login('inactive@example.test'),
            $this->login('family@example.test'),
            $this->login('norole@example.test'),
        ];

        foreach ($attempts as $response) {
            $response->assertStatus(422)->assertExactJson([
                'message' => LoginRequest::FAILED,
                'errors' => ['email' => [LoginRequest::FAILED]],
            ]);
        }
        $this->assertGuest('web');
    }

    public function test_failed_logins_are_rate_limited_per_email_and_ip(): void
    {
        $this->staff();
        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->login('staff@example.test', 'wrong-password-1')->assertStatus(422);
        }

        // Locked out — even the correct password is refused for now.
        $this->login('staff@example.test')->assertStatus(429)->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'محاولات'));
        $this->assertGuest('web');
        // Another email from the same IP is not locked by this counter.
        $this->staff('REVIEWER', ['email' => 'other@example.test']);
        $this->login('other@example.test')->assertOk();

        RateLimiter::clear('login|staff@example.test|127.0.0.1');
    }

    public function test_every_attempt_counts_towards_a_per_ip_ceiling(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->login("user{$i}@example.test");
        }
        $this->login('another@example.test')->assertStatus(429);
    }

    public function test_me_returns_only_safe_fields_and_effective_permissions(): void
    {
        $user = $this->staff('REPORTS_VIEWER');

        $this->getJson('/api/v1/me')->assertUnauthorized();

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['user'], array_keys($response->json()));
        $this->assertSame(['name', 'email', 'role', 'role_label', 'permissions'], array_keys($response->json('user')));
        $this->assertSame('REPORTS_VIEWER', $response->json('user.role'));
        $this->assertSame('مطلع على التقارير', $response->json('user.role_label'));
        $this->assertEqualsCanonicalizing($user->getAllPermissions()->pluck('name')->all(), $response->json('user.permissions'));
        $this->assertContains('export.basic', $response->json('user.permissions'));
        $this->assertNotContains('need.view', $response->json('user.permissions'));
        foreach (['"id"', 'password', 'remember_token', 'is_active', 'created_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->staff();
        $this->login('staff@example.test')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/families')->assertUnauthorized();
    }

    public function test_a_user_deactivated_during_a_session_is_denied_on_the_next_request(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->getJson('/api/v1/families')->assertOk();

        User::whereKey($user->id)->update(['is_active' => false]);
        // The next request loads the user from the session again.
        Auth::guard('web')->setUser($user->fresh());

        $this->getJson('/api/v1/families')->assertUnauthorized();
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_dev_login_does_not_exist_outside_local(): void
    {
        $this->assertFalse(app()->environment('local'));
        $this->get('/dev-login')->assertNotFound();
        $this->get('/dev-logout')->assertNotFound();
    }
}
