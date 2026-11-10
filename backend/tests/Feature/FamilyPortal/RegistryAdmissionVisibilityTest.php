<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Models\User;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\RevokesFamilyUserAdmissionGrant;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 RR-01: `registry_admission` on GET /api/v1/family/me, the
 * visibility-only answer of FamilyRegistryAdmissionPolicy::allowsAccount() — the same
 * check the discovery and admission gates refuse with. Reading it reads no registry,
 * writes no audit row and counts no limit, and it exposes no mode or allowlist. The
 * routes keep authorizing on their own. Synthetic data only.
 */
class RegistryAdmissionVisibilityTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase, RevokesFamilyUserAdmissionGrant;

    private const ME = '/api/v1/family/me';

    private const DISCOVERY = '/api/v1/family/household/registry-children';

    private array $head;

    private AdmissionRegistrySource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->head = $this->activatedHead('123456789');
        // A counting synthetic source; the real registry must never be reached.
        $this->source = new AdmissionRegistrySource(7);
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
    }

    private function visible(?User $as = null): bool
    {
        $response = $this->actingAs(($as ?? $this->head['user'])->fresh())->getJson(self::ME)->assertOk();
        $value = $response->json('user.registry_admission');
        $this->assertIsBool($value);

        return $value;
    }

    private function grant(?User $user = null): void
    {
        ($user ?? $this->head['user'])->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
    }

    private function mode(mixed $mode, mixed $pilotIds = []): void
    {
        config(['family_registry_admission.mode' => $mode, 'family_registry_admission.pilot_family_ids' => $pilotIds]);
    }

    public function test_the_shipped_configuration_hides_it(): void
    {
        $this->assertSame('OFF', config('family_registry_admission.mode') ?? 'OFF');
        $this->assertFalse($this->visible());
        $this->grant();
        $this->assertFalse($this->visible(), 'OFF hides it even with the permission');
    }

    public function test_on_shows_it_only_with_the_permission(): void
    {
        $this->mode('ON');
        $this->revokeFamilyUserAdmissionGrant();
        $this->assertFalse($this->visible(), 'without the permission');
        $this->grant();
        $this->assertTrue($this->visible());
    }

    public function test_pilot_shows_it_only_to_an_allow_listed_family_with_the_permission(): void
    {
        $this->grant();
        $other = $this->activatedHead('223456789');
        $this->grant($other['user']);
        $this->mode('PILOT', [(int) $this->head['family']->id]);

        $this->assertTrue($this->visible());
        $this->assertFalse($this->visible($other['user']), 'another Family');

        foreach ([[], null, 'not-a-list', [0, -1]] as $unreadable) {
            $this->mode('PILOT', $unreadable);
            $this->assertFalse($this->visible(), 'an empty or unreadable allowlist allows nobody');
        }
    }

    public function test_an_invalid_or_missing_mode_hides_it(): void
    {
        $this->grant();
        foreach (['', 'on ', 'YES', 'ENABLED', null, 1, true] as $mode) {
            $this->mode($mode);
            $expected = is_string($mode) && strtoupper(trim($mode)) === 'ON';
            $this->assertSame($expected, $this->visible(), var_export($mode, true));
        }
    }

    public function test_an_account_without_a_household_context_or_the_portal_permission_never_sees_it(): void
    {
        $this->mode('ON');
        // A guest and a Staff account never reach the field at all.
        $this->getJson(self::ME)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::ME)->assertForbidden();

        // A family-side account with the permission but no household context (not a head).
        $member = $this->familyUser();
        $this->grant($member);
        $this->assertFalse($this->visible($member));

        // A head whose role lost the Family Portal permission.
        $this->grant();
        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->visible());
    }

    public function test_reading_it_reads_no_registry_records_nothing_and_counts_nothing(): void
    {
        $this->app->instance(RegistryChildSource::class, $this->source);
        $this->mode('ON');
        $this->grant();
        $id = $this->head['user']->id;

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->visible());
        }

        $this->assertSame(0, $this->source->reads);
        $this->assertSame(0, DB::table('family_registry_admission_events')->count());
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
        foreach (['discovery', 'admission'] as $gate) {
            $this->assertSame(0, RateLimiter::attempts("family-registry-{$gate}|user|{$id}|minute"));
        }
    }

    public function test_it_exposes_no_mode_allowlist_or_reason(): void
    {
        $this->grant();
        $this->mode('PILOT', [(int) $this->head['family']->id, 987654]);
        $body = (string) $this->actingAs($this->head['user']->fresh())->getJson(self::ME)->assertOk()->getContent();

        $this->assertStringContainsString('"registry_admission":true', $body);
        foreach (['PILOT', 'pilot', 'mode', '987654', 'admit-from-registry', 'allowlist'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_routes_keep_authorizing_and_agree_with_it(): void
    {
        // No source is bound, as shipped: a passing gate answers 503, a refusing one 404.
        $other = $this->activatedHead('223456789');
        $member = $this->familyUser();
        foreach ([$this->head['user'], $other['user'], $member] as $user) {
            $this->grant($user);
        }
        $cases = [
            ['OFF', [], $this->head['user']],
            ['ON', [], $this->head['user']],
            ['PILOT', [(int) $this->head['family']->id], $this->head['user']],
            ['PILOT', [(int) $this->head['family']->id], $other['user']],
            ['ON', [], $member],
        ];
        foreach ($cases as [$mode, $ids, $user]) {
            $this->mode($mode, $ids);
            $visible = $this->visible($user);
            $status = $this->actingAs($user->fresh())->getJson(self::DISCOVERY)->status();
            $label = "{$mode} user {$user->id}";
            if ($visible) {
                $this->assertSame(503, $status, $label);
            } else {
                $this->assertContains($status, [403, 404], $label);
            }
            RateLimiter::clear("family-registry-discovery|user|{$user->id}|minute");
            RateLimiter::clear("family-registry-discovery|user|{$user->id}|hour");
        }

        // Visibility grants nothing: the permission revoked, the route refuses at once.
        $this->mode('ON');
        $this->revokeFamilyUserAdmissionGrant();
        $this->head['user']->revokePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->actingAs($this->head['user']->fresh())->getJson(self::DISCOVERY)->assertNotFound()->assertJsonPath('code', 'ADMISSION_UNAVAILABLE');
        $this->assertSame(0, $this->source->reads);
    }
}
