<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\MobileTrustStatus;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1E: the Staff API for mobile trust (docs/06 §22b) — view, grant,
 * revoke. Staff-side only, permission-gated, no family-side endpoint.
 * Synthetic data only.
 */
class MobileTrustApiTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const MOBILE = '0591234567';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->admin = $this->staff('ADMINISTRATOR', 'مسؤول التوثيق');
    }

    private function staff(string $role, ?string $name = null): User
    {
        return tap(User::factory()->create($name ? ['name' => $name] : []), fn (User $u) => $u->assignRole($role));
    }

    private function person(?string $mobile = self::MOBILE): Person
    {
        return Person::factory()->create(['mobile' => $mobile]);
    }

    private function show(Person $person, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->getJson("/api/v1/people/{$person->person_code}/mobile-trust");
    }

    private function grant(Person $person, array $body = ['verification_method' => 'IN_PERSON'], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/people/{$person->person_code}/mobile-trust", $body);
    }

    private function revoke(Person $person, array $body = ['reason' => 'REPORTED_LOST'], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/people/{$person->person_code}/mobile-trust/revoke", $body);
    }

    public function test_the_state_is_derived_for_every_situation(): void
    {
        $this->show($this->person(null))->assertOk()
            ->assertJsonPath('data.state', 'NO_MOBILE')
            ->assertJsonPath('data.mobile_masked', null)
            ->assertJsonPath('data.history', []);

        $person = $this->person();
        $this->show($person)->assertOk()
            ->assertJsonPath('data.person_code', $person->person_code)
            ->assertJsonPath('data.state', 'UNVERIFIED')
            ->assertJsonPath('data.mobile_masked', '********67');

        $this->grant($person)->assertCreated()->assertJsonPath('data.state', 'TRUSTED');
        $this->show($person)->assertJsonPath('data.state', 'TRUSTED')
            ->assertJsonPath('data.history.0.status', 'TRUSTED')
            ->assertJsonPath('data.history.0.verification_method', 'IN_PERSON')
            ->assertJsonPath('data.history.0.verified_by', 'مسؤول التوثيق')
            ->assertJsonPath('data.history.0.mobile_masked', '********67');

        $this->revoke($person, ['reason' => 'NOT_OWNER'])->assertOk()->assertJsonPath('data.state', 'REVOKED');
        $this->show($person)
            ->assertJsonPath('data.history.0.status', 'REVOKED')
            ->assertJsonPath('data.history.0.revoke_reason', 'NOT_OWNER')
            ->assertJsonPath('data.history.0.revoked_by', 'مسؤول التوثيق');

        // A new grant is a new row; a changed number makes it STALE.
        $this->grant($person, ['verification_method' => 'STAFF_CALLBACK'])->assertCreated();
        $person->fresh()->forceFill(['mobile' => '0567654321'])->save();
        $this->show($person)->assertJsonPath('data.state', 'STALE')
            ->assertJsonPath('data.mobile_masked', '********21')
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.status', 'STALE')
            ->assertJsonPath('data.history.1.status', 'REVOKED');
    }

    public function test_grant_trusts_the_stored_mobile_and_refuses_a_number_in_the_request(): void
    {
        $person = $this->person();

        $this->grant($person, ['verification_method' => 'IN_PERSON', 'mobile' => '0567654321'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->assertSame(0, PersonMobileTrust::count());

        $this->grant($person, ['verification_method' => 'AUTHORIZED_RECORD_REVIEW'])->assertCreated();
        $trust = PersonMobileTrust::sole();
        $this->assertSame($person->id, $trust->person_id);
        $this->assertSame('67', $trust->mobile_last2);
        $this->assertSame($this->admin->id, $trust->verified_by);
    }

    public function test_validation_and_business_refusals(): void
    {
        $person = $this->person();

        $this->grant($person, [])->assertUnprocessable()->assertJsonValidationErrors('verification_method');
        $this->grant($person, ['verification_method' => 'SMS_ONLY'])->assertUnprocessable()->assertJsonValidationErrors('verification_method');
        $this->revoke($person, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->revoke($person, ['reason' => 'because I said so'])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Nothing trusted yet: a revoke is a conflict.
        $this->revoke($person)->assertStatus(409)->assertJsonPath('code', 'NOT_TRUSTED');

        // No valid mobile.
        $this->grant($this->person(null))->assertStatus(422)->assertJsonPath('code', 'NO_VALID_MOBILE');
        $this->grant($this->person('591234567'))->assertStatus(422)->assertJsonPath('code', 'NO_VALID_MOBILE');

        // A duplicate grant is a conflict and rewrites nothing.
        $this->grant($person)->assertCreated();
        $this->grant($person, ['verification_method' => 'STAFF_CALLBACK'], $this->staff('SUPER_ADMIN'))
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_TRUSTED');
        $this->assertSame('IN_PERSON', PersonMobileTrust::sole()->verification_method->value);
        $this->assertSame($this->admin->id, PersonMobileTrust::sole()->verified_by);

        // An unknown or soft-deleted Person is simply not found.
        $this->actingAs($this->admin)->getJson('/api/v1/people/PER-NONE/mobile-trust')->assertNotFound();
        $deleted = $this->person();
        $deleted->delete();
        $this->show($deleted)->assertNotFound();
        $this->grant($deleted)->assertNotFound();
    }

    public function test_only_super_admin_and_administrator_may_view_grant_and_revoke(): void
    {
        $person = $this->person();
        $this->grant($person)->assertCreated();

        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $user = $this->staff($role);
            $this->show($person, $user)->assertForbidden();
            $this->grant($this->person(), ['verification_method' => 'IN_PERSON'], $user)->assertForbidden();
            $this->revoke($person, ['reason' => 'ADMINISTRATIVE'], $user)->assertForbidden();
        }
        $this->assertSame(1, PersonMobileTrust::count());
        $this->assertSame(MobileTrustStatus::TRUSTED, PersonMobileTrust::sole()->status);

        $super = $this->staff('SUPER_ADMIN');
        $this->show($person, $super)->assertOk();
        $this->revoke($person, ['reason' => 'ADMINISTRATIVE'], $super)->assertOk();
        $this->grant($person, ['verification_method' => 'IN_PERSON'], $super)->assertCreated();
    }

    public function test_family_side_and_role_less_accounts_never_reach_the_api(): void
    {
        $person = $this->person();
        $permissions = ['person-mobile-trust.view', 'person-mobile-trust.grant', 'person-mobile-trust.revoke'];
        $accounts = [
            $this->familyUser(),
            $this->familyUser(['FAMILY_USER', 'COORDINATOR']),
            $this->familyUser(['COORDINATOR']),
            $this->familyUser(['ADMINISTRATOR', 'FAMILY_USER']),
            User::factory()->create(),
        ];

        foreach ($accounts as $user) {
            // Even holding every permission directly.
            $user->givePermissionTo($permissions);
            foreach ([$this->show($person, $user->fresh()), $this->grant($person, ['verification_method' => 'IN_PERSON'], $user->fresh()),
                $this->revoke($person, ['reason' => 'ADMINISTRATIVE'], $user->fresh())] as $response) {
                $response->assertStatus(403)->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
            }
        }
        $this->assertSame(0, PersonMobileTrust::count());
    }

    public function test_guests_are_unauthenticated(): void
    {
        $person = $this->person();

        $this->getJson("/api/v1/people/{$person->person_code}/mobile-trust")->assertUnauthorized();
        $this->postJson("/api/v1/people/{$person->person_code}/mobile-trust", ['verification_method' => 'IN_PERSON'])->assertUnauthorized();
        $this->postJson("/api/v1/people/{$person->person_code}/mobile-trust/revoke", ['reason' => 'ADMINISTRATIVE'])->assertUnauthorized();
    }

    public function test_the_routes_sit_behind_the_staff_boundary_with_their_own_permission(): void
    {
        $expected = [
            'GET api/v1/people/{person}/mobile-trust' => 'can:person-mobile-trust.view',
            'POST api/v1/people/{person}/mobile-trust' => 'can:person-mobile-trust.grant',
            'POST api/v1/people/{person}/mobile-trust/revoke' => 'can:person-mobile-trust.revoke',
        ];
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'mobile-trust')) {
                continue;
            }
            $key = $route->methods()[0].' '.$route->uri();
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $key);
            $this->assertContains('staff.side', $middleware, $key);
            $this->assertContains($expected[$key] ?? 'missing', $middleware, $key);
            $found[] = $key;
        }
        // Exactly these three: no family-side or coordinator endpoint.
        $this->assertEqualsCanonicalizing(array_keys($expected), $found);
    }

    public function test_responses_expose_no_fingerprint_number_or_internal_id(): void
    {
        $person = $this->person();
        $this->grant($person)->assertCreated();
        $trust = PersonMobileTrust::sole();

        foreach ([$this->show($person), $this->revoke($person)] as $response) {
            $raw = $response->getContent();
            $this->assertStringNotContainsString(self::MOBILE, $raw);
            $this->assertStringNotContainsString($trust->mobile_fingerprint, $raw);
            foreach (['mobile_fingerprint', 'key_version', 'code_hash', 'person_id', 'verified_by_id', 'login_key'] as $key) {
                $this->assertStringNotContainsString($key, $raw);
            }
            $response->assertJsonPath('data.history.0.id', $trust->uuid);
        }
    }

    public function test_grant_and_revoke_are_recorded_as_security_events(): void
    {
        $person = $this->person();
        $this->grant($person)->assertCreated();
        $this->revoke($person, ['reason' => 'VERIFICATION_ERROR'])->assertOk();

        $events = AuthSecurityEvent::orderBy('id')->get();
        $this->assertSame(['MOBILE_TRUST_GRANTED', 'MOBILE_TRUST_REVOKED'], $events->map(fn ($e) => $e->event_type->value)->all());
        $this->assertSame(['IN_PERSON', 'VERIFICATION_ERROR'], $events->pluck('reason_code')->all());
        foreach ($events as $event) {
            $this->assertSame($this->admin->id, $event->actor_user_id);
            $this->assertStringNotContainsString(self::MOBILE, json_encode($event->getAttributes()));
        }
    }
}
