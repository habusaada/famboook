<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * Pilot topology (docs/08 §1): with ADMIN_DOMAIN set, Filament answers only
 * on admin.famboook.com, never on the API host. The panel's routes are
 * registered at boot, so the variable is set before the application starts.
 */
class AdminDomainTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ADMIN_DOMAIN=admin.famboook.test');
        $_ENV['ADMIN_DOMAIN'] = $_SERVER['ADMIN_DOMAIN'] = 'admin.famboook.test';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('ADMIN_DOMAIN');
        unset($_ENV['ADMIN_DOMAIN'], $_SERVER['ADMIN_DOMAIN']);
    }

    public function test_filament_is_served_only_on_the_admin_host(): void
    {
        $this->get('http://admin.famboook.test/admin/login')->assertOk();

        $this->get('http://api.famboook.test/admin/login')->assertNotFound();
        $this->get('http://api.famboook.test/admin')->assertNotFound();
    }

    public function test_the_api_is_unaffected(): void
    {
        $this->getJson('http://api.famboook.test/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
    }
}
