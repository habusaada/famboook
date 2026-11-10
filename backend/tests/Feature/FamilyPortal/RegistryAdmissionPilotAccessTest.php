<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyPortal\FamilyRegistryAdmissionPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\RevokesFamilyUserAdmissionGrant;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 PILOT access (G-07): famboook:registry-admission-pilot-access grants the
 * admission permission to ONE allowlisted household head's account only, never through a
 * role, and revokes it; the readiness command BLOCKS a PILOT whose allowlist is empty, whose
 * permission sits on a role, or whose holders are outside the pilot. Synthetic data only.
 */
class RegistryAdmissionPilotAccessTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase, RevokesFamilyUserAdmissionGrant;

    private const HEAD = '123456789';

    private const APPROVAL = 'G-07/TEST-0001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        // The per-account PILOT model presumes no role grant (now FAMILY_USER holds it as shipped).
        $this->revokeFamilyUserAdmissionGrant();
    }

    /** @return array{user: User, family: Family} */
    private function pilotHead(string $nationalId = self::HEAD): array
    {
        $head = $this->activatedHead($nationalId);
        config(['family_registry_admission.pilot_family_ids' => [...(array) config('family_registry_admission.pilot_family_ids'), $head['family']->id]]);

        return $head;
    }

    private function grant(string $familyCode, ?string $approval = self::APPROVAL): PendingCommand
    {
        return $this->artisan('famboook:registry-admission-pilot-access', array_filter(
            ['operation' => 'grant', '--family' => $familyCode, '--approval' => $approval], fn ($v) => $v !== null));
    }

    public function test_a_grant_needs_a_recorded_approval_reference(): void
    {
        $head = $this->pilotHead();
        $this->grant($head['family']->family_code, null)->expectsOutputToContain('--approval must name')->assertExitCode(1);
        $this->grant($head['family']->family_code, 'x')->assertExitCode(1);
        $this->grant($head['family']->family_code, 'G-07 approved; DROP')->assertExitCode(1);
        $this->assertFalse($head['user']->fresh()->can(AdmitRegistryChildAction::PERMISSION));
    }

    public function test_a_grant_is_refused_outside_the_allowlist_and_for_unknown_families(): void
    {
        $head = $this->activatedHead(self::HEAD);
        config(['family_registry_admission.pilot_family_ids' => [$head['family']->id + 1000]]);
        $this->grant($head['family']->family_code)->expectsOutputToContain('not in FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS')->assertExitCode(1);
        $this->grant('FAM-999999')->expectsOutputToContain('no such Family')->assertExitCode(1);
        $this->grant('999999')->expectsOutputToContain('no such Family')->assertExitCode(1);
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    public function test_a_grant_is_refused_while_any_role_holds_the_permission(): void
    {
        $head = $this->pilotHead();
        Role::findByName('FAMILY_USER', 'web')->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->grant($head['family']->family_code)->expectsOutputToContain('a role holds the admission permission')->assertExitCode(1);
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    public function test_a_grant_is_refused_when_the_head_has_no_eligible_account(): void
    {
        $head = $this->pilotHead();
        $head['user']->forceFill(['is_active' => false])->save();
        $this->grant($head['family']->family_code)->expectsOutputToContain('no single eligible Family Portal account')->assertExitCode(1);

        // A Family whose head never activated an account.
        [, $family] = $this->eligibleHead('223456789');
        config(['family_registry_admission.pilot_family_ids' => [$family->id]]);
        $this->grant($family->family_code)->assertExitCode(1);
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    public function test_a_grant_reaches_exactly_the_heads_account_and_logs_internal_ids_only(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });
        $head = $this->pilotHead();
        $other = $this->pilotHead('323456789');

        $this->grant($head['family']->family_code)
            ->expectsOutputToContain('(1 account)')
            ->doesntExpectOutputToContain(self::HEAD)
            ->assertExitCode(0);

        $this->assertTrue($head['user']->fresh()->hasDirectPermission(AdmitRegistryChildAction::PERMISSION));
        $this->assertFalse($other['user']->fresh()->can(AdmitRegistryChildAction::PERMISSION));
        $this->assertSame(0, Role::findByName('FAMILY_USER', 'web')->permissions()->where('name', AdmitRegistryChildAction::PERMISSION)->count());
        $this->assertSame(1, DB::table('model_has_permissions')->count());
        config(['family_registry_admission.mode' => 'PILOT']);
        $this->assertTrue(FamilyRegistryAdmissionPolicy::allowsAccount($head['user']->fresh(), app(FamilyAccessResolver::class)->familyContext($head['user']->fresh())));

        $this->assertCount(1, array_filter($logged, fn ($l) => str_contains($l, 'family_registry_admission.pilot_access.granted') && str_contains($l, self::APPROVAL)));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::HEAD, $line);
        }
        // The role grant revoked in setUp differs from the shipped baseline, which verify-permissions
        // refuses; with the baseline restored, the direct per-account grant still passes.
        $this->artisan('famboook:verify-permissions')->assertExitCode(1);
        $this->seed(RolePermissionSeeder::class);
        $this->assertTrue($head['user']->fresh()->hasDirectPermission(AdmitRegistryChildAction::PERMISSION));
        $this->artisan('famboook:verify-permissions')->assertExitCode(0);
    }

    public function test_revoke_and_revoke_all_remove_only_direct_grants(): void
    {
        $head = $this->pilotHead();
        $other = $this->pilotHead('323456789');
        $this->grant($head['family']->family_code)->assertExitCode(0);
        $this->grant($other['family']->family_code)->assertExitCode(0);

        $this->artisan('famboook:registry-admission-pilot-access', ['operation' => 'revoke', '--family' => $head['family']->family_code])
            ->expectsOutputToContain('from 1 account(s)')->assertExitCode(0);
        $this->assertFalse($head['user']->fresh()->can(AdmitRegistryChildAction::PERMISSION));
        $this->assertTrue($other['user']->fresh()->can(AdmitRegistryChildAction::PERMISSION));

        $this->artisan('famboook:registry-admission-pilot-access', ['operation' => 'revoke-all'])
            ->expectsOutputToContain('(1)')->assertExitCode(0);
        $this->assertFalse($other['user']->fresh()->can(AdmitRegistryChildAction::PERMISSION));
        $this->assertSame(0, DB::table('model_has_permissions')->count());
        $this->artisan('famboook:registry-admission-pilot-access', ['operation' => 'promote'])->assertExitCode(1);
    }

    public function test_readiness_blocks_a_pilot_without_allowlist_holders_or_with_a_role_grant(): void
    {
        config(['family_registry_admission.mode' => 'PILOT', 'family_registry_admission.pilot_family_ids' => []]);
        $this->artisan('famboook:registry-admission-readiness')
            ->expectsOutputToContain('[BLOCKED] ALLOWLIST')
            ->expectsOutputToContain('pilot accounts holding the permission: 0; of them outside the pilot: 0')
            ->expectsOutputToContain('[BLOCKED] GRANTS      every holder is an allowlisted household head — no pilot account holds the permission')
            ->assertExitCode(1);

        $head = $this->pilotHead();
        $this->grant($head['family']->family_code)->assertExitCode(0);
        $this->artisan('famboook:registry-admission-readiness')
            ->expectsOutputToContain('[PASS] ALLOWLIST')
            ->expectsOutputToContain('[PASS] SCOPE')
            ->expectsOutputToContain('pilot accounts holding the permission: 1; of them outside the pilot: 0')
            ->expectsOutputToContain('[PASS] GRANTS')
            ->assertExitCode(1);

        // The Family leaves the allowlist: its head's grant is now outside the pilot.
        config(['family_registry_admission.pilot_family_ids' => [$head['family']->id + 1000]]);
        $this->artisan('famboook:registry-admission-readiness')
            ->expectsOutputToContain('of them outside the pilot: 1')
            ->expectsOutputToContain('[BLOCKED] GRANTS')
            ->assertExitCode(1);

        Role::findByName('FAMILY_USER', 'web')->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->artisan('famboook:registry-admission-readiness')->expectsOutputToContain('[BLOCKED] SCOPE')->assertExitCode(1);
    }

    public function test_readiness_reports_no_pilot_scope_lines_while_off(): void
    {
        $this->artisan('famboook:registry-admission-readiness')
            ->doesntExpectOutputToContain('ALLOWLIST')
            ->doesntExpectOutputToContain('GRANTS')
            ->assertExitCode(1);
    }
}
