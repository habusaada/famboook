<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * FB-WEB-04: config/cors.php allows an explicit list of origins, so the two
 * first-party hosts (famboook.com, app.famboook.com) can use the API with
 * credentials. docs/06 §90: only approved application origins are allowed.
 */
class CorsConfigurationTest extends TestCase
{
    private const VARIABLES = ['CORS_ALLOWED_ORIGINS', 'FRONTEND_URL'];

    private const PRODUCTION = 'https://famboook.com,https://app.famboook.com';

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $server]) {
            $this->setVariable($name, $env);
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }

        parent::tearDown();
    }

    private function setVariable(string $name, mixed $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    /** @return array<string, mixed> The config file, evaluated with these variables. */
    private function corsWith(?string $allowedOrigins, ?string $frontendUrl): array
    {
        $this->setVariable('CORS_ALLOWED_ORIGINS', $allowedOrigins);
        $this->setVariable('FRONTEND_URL', $frontendUrl);

        return require config_path('cors.php');
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_it_allows_exactly_the_listed_origins(): void
    {
        $cors = $this->corsWith(self::PRODUCTION, 'https://famboook.com');

        $this->assertSame(['https://famboook.com', 'https://app.famboook.com'], $cors['allowed_origins']);
    }

    public function test_it_trims_spaces_and_ignores_empty_entries(): void
    {
        $cors = $this->corsWith(' https://famboook.com , ,https://app.famboook.com, ', null);

        $this->assertSame(['https://famboook.com', 'https://app.famboook.com'], $cors['allowed_origins']);
    }

    public function test_without_a_list_it_falls_back_to_the_frontend_url(): void
    {
        $this->assertSame(['https://famboook.com'], $this->corsWith(null, 'https://famboook.com')['allowed_origins']);
        $this->assertSame(['https://famboook.com'], $this->corsWith('', 'https://famboook.com')['allowed_origins']);
    }

    public function test_without_any_variable_it_keeps_the_local_default(): void
    {
        $this->assertSame(['http://localhost:3000'], $this->corsWith(null, null)['allowed_origins']);
    }

    public function test_a_wildcard_is_never_accepted(): void
    {
        $this->assertSame(['https://famboook.com'], $this->corsWith('*,https://famboook.com', null)['allowed_origins']);
        $this->assertSame([], $this->corsWith('*', null)['allowed_origins']);
    }

    public function test_the_other_settings_are_unchanged(): void
    {
        $cors = $this->corsWith(self::PRODUCTION, null);

        $this->assertSame(['api/*', 'sanctum/csrf-cookie', 'dev-login', 'dev-logout'], $cors['paths']);
        $this->assertSame(['*'], $cors['allowed_methods']);
        $this->assertSame(['*'], $cors['allowed_headers']);
        $this->assertSame([], $cors['allowed_origins_patterns']);
        $this->assertSame([], $cors['exposed_headers']);
        $this->assertSame(0, $cors['max_age']);
        $this->assertTrue($cors['supports_credentials']);
    }

    public function test_a_preflight_succeeds_with_credentials_for_both_first_party_hosts(): void
    {
        config(['cors' => $this->corsWith(self::PRODUCTION, null)]);

        foreach (['https://famboook.com', 'https://app.famboook.com'] as $origin) {
            $response = $this->preflight($origin);

            $response->assertNoContent();
            $response->assertHeader('Access-Control-Allow-Origin', $origin);
            $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_a_preflight_from_any_other_origin_gets_no_allow_origin(): void
    {
        config(['cors' => $this->corsWith(self::PRODUCTION, null)]);

        foreach (['https://evil.example', 'https://app.famboook.com.evil.example', 'https://evil-app.famboook.com', 'http://app.famboook.com'] as $origin) {
            $this->preflight($origin)->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
}
