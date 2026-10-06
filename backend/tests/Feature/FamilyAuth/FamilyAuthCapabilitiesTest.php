<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\AuthSecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-14 (docs/11 FP-ADR-065): GET /api/v1/family/auth/capabilities — the
 * three global Family Auth gates as booleans, public, outside every gate,
 * never cached, and nothing else. Synthetic data only.
 */
class FamilyAuthCapabilitiesTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const URL = '/api/v1/family/auth/capabilities';

    private function capabilities(): TestResponse
    {
        return $this->getJson(self::URL);
    }

    private function flags(bool $activation, bool $login, bool $reset): void
    {
        config([
            'family_auth.activation_enabled' => $activation,
            'family_auth.login_enabled' => $login,
            'family_auth.password_reset_enabled' => $reset,
        ]);
    }

    /** @return array<string, array{0: bool, 1: bool, 2: bool}> */
    public static function combinations(): array
    {
        $cases = [];
        foreach ([false, true] as $activation) {
            foreach ([false, true] as $login) {
                foreach ([false, true] as $reset) {
                    $name = sprintf('activation=%s login=%s reset=%s', ...array_map(fn (bool $on) => $on ? 'on' : 'off', [$activation, $login, $reset]));
                    $cases[$name] = [$activation, $login, $reset];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('combinations')]
    public function test_each_flag_is_reported_independently_and_exactly(bool $activation, bool $login, bool $reset): void
    {
        $this->flags($activation, $login, $reset);

        $this->capabilities()->assertOk()->assertExactJson(['data' => [
            'activation' => $activation,
            'login' => $login,
            'password_reset' => $reset,
        ]]);
    }

    public function test_all_off_by_default_matches_the_closed_gates(): void
    {
        // The defaults of config/family_auth.php: every surface closed.
        $this->assertFalse(config('family_auth.activation_enabled'));
        $this->assertFalse(config('family_auth.login_enabled'));
        $this->assertFalse(config('family_auth.password_reset_enabled'));

        $this->capabilities()->assertOk()->assertExactJson(['data' => ['activation' => false, 'login' => false, 'password_reset' => false]]);

        // What it reports is what the gates do.
        $this->postJson('/api/v1/family/auth/password/reset/start', ['national_id' => '123456789'])
            ->assertStatus(503)->assertJsonPath('code', 'PASSWORD_RESET_UNAVAILABLE');
        $this->postJson('/api/v1/family/auth/login', ['national_id' => '123456789', 'password' => 'synthetic-pass-1'])
            ->assertStatus(503);
        $this->postJson('/api/v1/family/auth/activation/start', ['national_id' => '123456789'])
            ->assertStatus(503);
    }

    public function test_only_a_strict_true_counts_as_open(): void
    {
        config([
            'family_auth.activation_enabled' => 'true',
            'family_auth.login_enabled' => 1,
            'family_auth.password_reset_enabled' => 'yes',
        ]);

        $this->capabilities()->assertOk()->assertExactJson(['data' => ['activation' => false, 'login' => false, 'password_reset' => false]]);
    }

    public function test_the_answer_is_never_cached(): void
    {
        $cacheControl = (string) $this->capabilities()->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_it_needs_no_authentication_and_ignores_who_is_signed_in(): void
    {
        $this->flags(true, true, false);
        $expected = ['data' => ['activation' => true, 'login' => true, 'password_reset' => false]];

        $this->assertGuest('web');
        $this->capabilities()->assertOk()->assertExactJson($expected);

        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $family = $this->activatedHead()['user'];
        $this->actingAs($family, 'web');
        $this->capabilities()->assertOk()->assertExactJson($expected);

        $this->app['auth']->forgetGuards();
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $this->actingAs($staff, 'web');
        $this->capabilities()->assertOk()->assertExactJson($expected);
    }

    public function test_it_sits_outside_every_family_auth_gate(): void
    {
        $this->flags(false, false, false);
        $route = app('router')->getRoutes()->getByName('family.auth.capabilities');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['family.activation', 'family.login', 'family.password-reset', 'auth:sanctum', 'family.side'] as $middleware) {
            $this->assertNotContains($middleware, $route->gatherMiddleware());
        }
        $this->capabilities()->assertOk();
    }

    public function test_it_takes_no_input_and_reveals_nothing_else(): void
    {
        $this->flags(true, true, true);

        $response = $this->getJson(self::URL.'?national_id=123456789&user=1')->assertOk();

        $response->assertExactJson(['data' => ['activation' => true, 'login' => true, 'password_reset' => true]]);
        foreach (['FAMILY_', 'sms', 'tweet', 'limit', 'throttle', 'min_response', 'fingerprint', '123456789'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $response->getContent());
        }
        // A read records nothing.
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_writes_are_not_accepted(): void
    {
        $this->postJson(self::URL)->assertStatus(405);
    }
}
