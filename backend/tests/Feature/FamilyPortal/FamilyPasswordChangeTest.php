<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\UserPersonLinkStatus;
use App\Models\AuthSecurityEvent;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-16 (docs/11 FP-ADR-078): POST /api/v1/family/account/password — the
 * signed-in household head changes their OWN password. Current password
 * required, new password under the Family policy and different from the
 * current one, every session ended and this browser signed in afresh,
 * PASSWORD_CHANGED recorded without any password, 5 attempts per 15 minutes
 * per user, behind its own gate (off by default). Synthetic data only.
 */
class FamilyPasswordChangeTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/account/password';

    private const NATIONAL_ID = '123456789';

    /** The password every factory-made account has. Synthetic. */
    private const OLD_PASSWORD = 'password';

    private const NEW_PASSWORD = 'a new synthetic passphrase';

    /** A first-party browser request: the only kind that carries a session. */
    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    /** @var array<string, mixed> */
    private array $head;

    private User $user;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['family_auth.password_change_enabled' => true, 'family_auth.login_enabled' => true]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->user = $this->head['user'];
    }

    private function change(array $overrides = [], array $headers = self::BROWSER, ?User $as = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(($as ?? $this->user)->fresh(), 'web');

        return $this->postJson(self::URI, [
            'current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, ...$overrides,
        ], $headers);
    }

    private function login(string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->logout();

        return $this->postJson('/api/v1/family/auth/login', ['national_id' => self::NATIONAL_ID, 'password' => $password], self::BROWSER);
    }

    private function assertPasswordUnchanged(): void
    {
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'SESSIONS_REVOKED')->count());
    }

    /** @return list<array{string, ?string}> outcome and reason of each PASSWORD_CHANGED event */
    private function changeEvents(): array
    {
        return AuthSecurityEvent::where('event_type', 'PASSWORD_CHANGED')->orderBy('id')->get()
            ->map(fn (AuthSecurityEvent $e) => [$e->outcome->value, $e->reason_code])->all();
    }

    // ------------------------------------------------------------ success, sessions, audit

    public function test_the_current_password_and_a_new_one_change_the_password_and_keep_this_browser_signed_in(): void
    {
        $this->user->forceFill(['remember_token' => 'an-old-synthetic-remember-token'])->save();
        $response = $this->change()->assertOk();

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        // The old remember token is gone (cleared by the revocation, then cycled by the fresh sign-in).
        $this->assertNotSame('an-old-synthetic-remember-token', $fresh->remember_token);
        $response->assertJsonPath('user.display_name', $this->head['person']->full_name)->assertJsonPath('user.context.available', true);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($this->user, 'web');

        $this->assertSame([['SUCCESS', null]], $this->changeEvents());
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_CHANGED')->sole();
        $this->assertSame([$this->user->id, $this->head['person']->id, $this->head['link']->id], [$event->user_id, $event->person_id, $event->user_person_link_id]);
        $revoked = AuthSecurityEvent::where('event_type', 'SESSIONS_REVOKED')->sole();
        $this->assertSame(['SUCCESS', 'PASSWORD_CHANGED', $this->user->id], [$revoked->outcome->value, $revoked->reason_code, $revoked->user_id]);

        // The old password no longer signs in; the new one does.
        $this->login(self::OLD_PASSWORD)->assertUnauthorized();
        $this->login(self::NEW_PASSWORD)->assertOk();
    }

    public function test_every_earlier_session_is_ended_and_this_browser_gets_a_new_one(): void
    {
        config(['session.driver' => 'database']);
        $this->sessionRowFor($this->user);
        $this->sessionRowFor($this->user);
        $other = $this->activatedHead('555555550');
        $this->sessionRowFor($other['user']);
        $old = DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all();
        $this->assertCount(2, $old);

        $this->change()->assertOk();

        $rows = DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all();
        $this->assertCount(1, $rows, 'only the new session of this browser remains');
        $this->assertNotContains($rows[0], $old);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other['user']->id)->count());
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_the_session_id_is_regenerated(): void
    {
        $this->actingAs($this->user, 'web');
        $this->startSession();
        $before = session()->getId();

        $this->postJson(self::URI, ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], self::BROWSER)
            ->assertOk();

        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_no_password_reaches_an_event_a_log_or_the_response(): void
    {
        $this->change(['current_password' => 'wrong synthetic secret'])->assertUnprocessable();
        $success = $this->change()->assertOk();

        $events = json_encode(AuthSecurityEvent::all()->toArray());
        foreach ([self::OLD_PASSWORD.'"', self::NEW_PASSWORD, 'wrong synthetic secret'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $events, $secret);
            $this->assertStringNotContainsString($secret, implode("\n", $this->logged), $secret);
            $this->assertStringNotContainsString($secret, (string) $success->getContent(), $secret);
        }
    }

    // ------------------------------------------------------------ refusals

    public function test_a_wrong_current_password_is_refused_without_locking_or_ending_the_session(): void
    {
        $this->change(['current_password' => 'not the password'])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);

        $this->assertPasswordUnchanged();
        $this->assertSame([['FAILURE', 'WRONG_CURRENT_PASSWORD']], $this->changeEvents());
        // Still signed in, and the account still logs in.
        $this->getJson('/api/v1/family/account', self::BROWSER)->assertOk();
        $this->login(self::OLD_PASSWORD)->assertOk();
    }

    public function test_an_identical_new_password_is_refused(): void
    {
        $this->change(['password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'كلمة المرور الجديدة مطابقة لكلمة المرور الحالية. اختر كلمة مرور مختلفة.']);

        $this->assertPasswordUnchanged();
        $this->assertSame([['FAILURE', 'PASSWORD_UNCHANGED']], $this->changeEvents());
    }

    public function test_the_new_password_follows_the_family_policy_and_the_72_byte_limit(): void
    {
        $cases = [
            'missing current' => [['current_password' => null], 'current_password'],
            'missing new' => [['password' => null], 'password'],
            'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'no confirmation' => [['password_confirmation' => null], 'password'],
            'mismatch' => [['password_confirmation' => 'another synthetic phrase'], 'password'],
            'over 72 bytes' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
            'arabic over 72 bytes' => [['password' => str_repeat('ب', 37), 'password_confirmation' => str_repeat('ب', 37)], 'password'],
        ];
        foreach ($cases as $label => [$overrides, $field]) {
            Cache::flush();
            $this->change($overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertPasswordUnchanged();
        }
        Cache::flush();
        $this->change(['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)])
            ->assertJsonPath('errors.password.0', 'كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.');
        // Validation refusals are not password checks: nothing is recorded.
        $this->assertSame([], $this->changeEvents());

        // Exactly 72 bytes (36 Arabic letters) is accepted.
        Cache::flush();
        $this->change(['password' => str_repeat('ب', 36), 'password_confirmation' => str_repeat('ب', 36)])->assertOk();
        $this->assertTrue(Hash::check(str_repeat('ب', 36), $this->user->fresh()->password));
    }

    public function test_a_request_without_a_session_is_refused_before_any_password_check(): void
    {
        $this->change([], [])->assertStatus(409)->assertExactJson([
            'message' => 'تعذّر تغيير كلمة المرور. حدّث الصفحة وحاول مرة أخرى.', 'code' => 'PASSWORD_CHANGE_FAILED',
        ]);
        $this->assertPasswordUnchanged();
        $this->assertSame([['DENIED', 'SESSION_REQUIRED']], $this->changeEvents());
    }

    // ------------------------------------------------------------ throttle

    public function test_five_attempts_per_fifteen_minutes_per_user_then_429_without_locking(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->change(['current_password' => 'wrong '.$i])->assertUnprocessable();
        }
        $this->change()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertPasswordUnchanged();
        $this->assertCount(5, $this->changeEvents());

        // Another user has their own bucket; this one is still signed in and can log in.
        $other = $this->activatedHead('555555550');
        $this->change(['current_password' => 'wrong'], self::BROWSER, $other['user'])->assertUnprocessable();
        $this->getJson('/api/v1/family/account', self::BROWSER)->assertOk();

        // After the window the user may try again — the account was never locked.
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->change()->assertOk();
    }

    // ------------------------------------------------------------ gate and boundaries

    public function test_the_gate_is_off_by_default_and_closed_means_nothing_happens(): void
    {
        $this->assertFalse((require config_path('family_auth.php'))['password_change_enabled']);

        config(['family_auth.password_change_enabled' => false]);
        for ($i = 0; $i < 6; $i++) {
            $this->change()->assertStatus(503)->assertExactJson(['message' => 'الخدمة غير متاحة حاليًا.', 'code' => 'PASSWORD_CHANGE_UNAVAILABLE']);
        }
        $this->assertPasswordUnchanged();
        $this->assertSame(0, AuthSecurityEvent::count());

        // A closed gate counts nothing: once opened, the first attempt works.
        config(['family_auth.password_change_enabled' => true]);
        $this->change()->assertOk();
    }

    public function test_the_gate_is_independent_of_the_other_gates_and_reported_by_capabilities(): void
    {
        config(['family_auth.login_enabled' => false, 'family_auth.password_reset_enabled' => false, 'family_auth.activation_enabled' => false]);
        $this->getJson('/api/v1/family/auth/capabilities')->assertJsonPath('data.password_change', true);
        $this->change()->assertOk();

        config(['family_auth.password_change_enabled' => false, 'family_auth.password_reset_enabled' => true]);
        $this->getJson('/api/v1/family/auth/capabilities')->assertJsonPath('data.password_change', false);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->postJson(self::URI, ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], self::BROWSER)
            ->assertUnauthorized();
        $this->assertPasswordUnchanged();
    }

    public function test_a_staff_account_never_enters_the_family_route(): void
    {
        $staff = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $staff->assignRole(StaffRoles::ALL[0]);

        $this->change([], self::BROWSER, $staff)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $staff->fresh()->password));
        $this->assertSame([], $this->changeEvents());
    }

    public function test_a_family_account_without_a_family_context_is_refused(): void
    {
        $this->head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED])->save();

        $this->change()->assertForbidden();
        $this->assertPasswordUnchanged();
        $this->assertSame([], $this->changeEvents());
    }

    public function test_no_identifier_in_the_body_can_choose_another_account(): void
    {
        $other = $this->activatedHead('555555550');

        $this->change(['user_id' => $other['user']->id, 'national_id' => '555555550'])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $other['user']->fresh()->password));
    }
}
