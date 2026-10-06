<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\GrantPersonMobileTrustAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileVerificationMethod;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyAccountController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3B.5: GET /api/v1/family/account — «حسابي», the signed-in household
 * head's OWN account facts: the activation date and the CURRENT mobile trust
 * state with the Family-masked mobile. The account comes only from the
 * family.context boundary; the projection is an explicit allow-list; no
 * trust history or security internal ever leaves. Synthetic data only.
 */
class FamilyAccountTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/account';

    private const MOBILE = '0591234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('ADMINISTRATOR'));
    }

    // -------------------------------------------------------------- contract

    public function test_the_head_gets_exactly_the_allow_listed_account_facts(): void
    {
        $head = $this->activatedHead();
        $head['link']->forceFill(['activated_at' => Carbon::parse('2026-10-01 09:00:00')])->save();
        $this->trustedMobile($head['person'], self::MOBILE);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(['activated_at', 'mobile'], array_keys($response->json('data')));
        $this->assertSame(['state', 'masked'], array_keys($response->json('data.mobile')));
        $this->assertSame(Carbon::parse('2026-10-01 09:00:00')->toIso8601String(), $response->json('data.activated_at'));
        $response->assertJsonPath('data.mobile.state', 'TRUSTED')
            ->assertJsonPath('data.mobile.masked', '05*****567');
    }

    public function test_responses_are_never_cached(): void
    {
        $head = $this->activatedHead();

        $cache = (string) $this->fetch($head['user'])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_the_mobile_mask_is_the_one_bayanati_shows(): void
    {
        $head = $this->activatedHead();
        $this->trustedMobile($head['person'], self::MOBILE);

        $account = $this->fetch($head['user'])->assertOk();
        $self = $this->fetch($head['user'], '/api/v1/family/self')->assertOk();

        $this->assertSame($self->json('data.mobile_masked'), $account->json('data.mobile.masked'));
    }

    public function test_a_missing_activation_date_stays_null(): void
    {
        $head = $this->activatedHead();
        $head['link']->forceFill(['activated_at' => null])->save();

        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.activated_at', null);
    }

    // ----------------------------------------------------------- trust state

    public function test_no_valid_mobile_is_no_mobile_with_a_null_mask(): void
    {
        $head = $this->activatedHead();
        $head['person']->forceFill(['mobile' => null])->save();

        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.mobile.state', 'NO_MOBILE')
            ->assertJsonPath('data.mobile.masked', null);
    }

    public function test_a_registered_but_never_verified_mobile_is_unverified(): void
    {
        $head = $this->activatedHead();
        $head['person']->forceFill(['mobile' => self::MOBILE])->save();

        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.mobile.state', 'UNVERIFIED')
            ->assertJsonPath('data.mobile.masked', '05*****567');
    }

    public function test_a_changed_mobile_makes_the_trust_stale(): void
    {
        $head = $this->activatedHead();
        $this->trustedMobile($head['person'], self::MOBILE);
        $head['person']->fresh()->forceFill(['mobile' => '0567654321'])->save();

        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.mobile.state', 'STALE')
            ->assertJsonPath('data.mobile.masked', '05*****321');
    }

    public function test_a_revoked_trust_then_a_new_staff_grant_follow_the_canonical_state(): void
    {
        $head = $this->activatedHead();
        $trust = $this->trustedMobile($head['person'], self::MOBILE);
        $admin = $this->admin();

        app(RevokePersonMobileTrustAction::class)->handle($admin, $trust, MobileTrustRevokeReason::NOT_OWNER);
        $revoked = $this->fetch($head['user'])->assertOk()->assertJsonPath('data.mobile.state', 'REVOKED');
        // The state only — never the reason, the revoker or the history.
        $this->assertStringNotContainsString('NOT_OWNER', $revoked->getContent());

        app(GrantPersonMobileTrustAction::class)->handle($admin, $head['person']->fresh(), MobileVerificationMethod::IN_PERSON);
        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.mobile.state', 'TRUSTED');
        $this->assertSame(2, PersonMobileTrust::where('person_id', $head['person']->id)->count());
    }

    public function test_without_a_usable_fingerprint_key_the_endpoint_fails_closed(): void
    {
        // UNAVAILABLE never reaches this page: the same key validates the
        // Family Auth identity, so family.context refuses first.
        $head = $this->activatedHead();
        $this->trustedMobile($head['person'], self::MOBILE);
        config(['family_auth.fingerprint.key' => null]);

        $this->fetch($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
    }

    // ------------------------------------------------------------- privacy

    public function test_no_security_internal_or_trust_history_appears(): void
    {
        $head = $this->activatedHead();
        $trust = $this->trustedMobile($head['person'], self::MOBILE);
        $head['person']->fresh()->forceFill(['mobile' => '0567654321'])->save();
        app(GrantPersonMobileTrustAction::class)->handle($this->admin(), $head['person']->fresh(), MobileVerificationMethod::STAFF_CALLBACK);

        $raw = $this->fetch($head['user'])->assertOk()->getContent();

        foreach ([self::MOBILE, '0567654321', '567654321', $trust->mobile_fingerprint, $trust->uuid, $head['identity']->login_key,
            $head['link']->uuid, $head['person']->person_code, $head['person']->national_id] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $raw);
        }
        foreach (['fingerprint', 'key_version', 'login_key', 'history', 'verified_by', 'revoked_by', 'revoke_reason',
            'verification_method', 'STAFF_CALLBACK', 'person_code', 'user_id', 'person_id', '"id"', 'uuid', 'roles',
            'permissions', 'session', 'otp', 'challenge'] as $key) {
            $this->assertStringNotContainsString($key, $raw, $key);
        }
    }

    public function test_reading_the_account_writes_nothing(): void
    {
        $head = $this->activatedHead();
        $this->trustedMobile($head['person'], self::MOBILE);
        $events = AuthSecurityEvent::count();
        $trusts = PersonMobileTrust::count();

        $this->fetch($head['user'])->assertOk();

        $this->assertSame($events, AuthSecurityEvent::count());
        $this->assertSame($trusts, PersonMobileTrust::count());
    }

    // ------------------------------------------------------- self scoping

    public function test_the_route_takes_no_target_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyAccountController::class.'@show');

        $this->assertSame('api/v1/family/account', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware(), $middleware);
        }
    }

    public function test_the_account_is_the_family_context_account_whatever_the_request_says(): void
    {
        $head = $this->activatedHead('123456789');
        $head['person']->forceFill(['mobile' => self::MOBILE])->save();
        $other = $this->activatedHead('222222222');
        $this->trustedMobile($other['person'], '0569999888');

        $spoofed = $this->fetch($head['user'], self::URI.'?person='.$other['person']->person_code
            .'&user_id='.$other['user']->id.'&person_id='.$other['person']->id)->assertOk();

        $spoofed->assertJsonPath('data.mobile.state', 'UNVERIFIED')->assertJsonPath('data.mobile.masked', '05*****567');
        $this->assertStringNotContainsString('888', $spoofed->getContent());
    }

    public function test_a_coordinator_gets_the_same_account_facts_and_no_scope(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $this->trustedMobile($head['person'], self::MOBILE);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 2);
        $this->assign($head['user'], $clan);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(['activated_at', 'mobile'], array_keys($response->json('data')));
        $response->assertJsonPath('data.mobile.state', 'TRUSTED');
        foreach ([$clan->code, $clan->name, $assigned->family_code, 'scope', 'CLAN', 'coordinator'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    // --------------------------------------------------------- the boundary

    public function test_a_guest_gets_the_json_401(): void
    {
        $this->getJson(self::URI)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_staff_coordinator_only_and_mixed_accounts_are_refused_by_family_side(): void
    {
        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('family-portal.access');
        $accounts['staff with the permission'] = $staff;
        $mixed = $this->activatedHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $response = $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('mobile', $response->getContent(), $label);
        }
    }

    public function test_a_lost_family_context_gets_the_generic_403(): void
    {
        $head = $this->activatedHead();
        $this->fetch($head['user'])->assertOk();

        $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $response = $this->fetch($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_deactivated_account_never_reaches_it(): void
    {
        $head = $this->activatedHead();
        $head['user']->forceFill(['is_active' => false])->save();

        $this->fetch($head['user'])->assertUnauthorized();
    }
}
