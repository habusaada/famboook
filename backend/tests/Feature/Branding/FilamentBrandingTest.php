<?php

namespace Tests\Feature\Branding;

use Tests\TestCase;

/**
 * FB-BRAND-01: the Filament administration shows the official Famboook logo
 * and icon — byte-for-byte copies of the frontend assets — and keeps its
 * existing colours.
 */
class FilamentBrandingTest extends TestCase
{
    public function test_the_admin_login_shows_the_official_logo_and_favicon(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('brand/FamboookLogoColored.svg', (string) $html);
        $this->assertMatchesRegularExpression('/<link[^>]+rel="icon"[^>]+brand\/FamboookLogoColoredIcon\.svg/', (string) $html);
    }

    public function test_the_served_assets_are_the_official_files(): void
    {
        $this->assertFileEquals(base_path('../frontend/public/brand/FamboookLogoColored.svg'), public_path('brand/FamboookLogoColored.svg'));
        $this->assertFileEquals(base_path('../frontend/public/brand/FamboookLogoColoredIcon.svg'), public_path('brand/FamboookLogoColoredIcon.svg'));
    }
}
