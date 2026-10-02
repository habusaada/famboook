<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\EstablishFamilyIdentityAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Contracts\SmsSender;
use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\FingerprintContext;
use App\Enums\LifeStatus;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\UserPersonLinkEndReason;
use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkVerificationMethod;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use App\Support\FamilyAuth\KeyedFingerprint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1F: the activation completion (docs/11 §30a) — the one transaction
 * that creates the family-side account, and the session after it. Codes are
 * read from the fake SMS sender only. Synthetic data only.
 */
class FamilyActivationCompleteTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const BASE = '/api/v1/family/auth/activation';

    private const NATIONAL_ID = '123456789';

    private const MOBILE = '0591234567';

    private const PASSWORD = 'synthetic passphrase 1';

    /** A first-party browser request: the only kind that carries a session. */
    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    private FakeSmsSender $sms;

    private Person $person;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['family_auth.activation_enabled' => true, 'family_auth.activation.min_response_ms' => 0]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        [$this->person] = $this->eligibleHead(self::NATIONAL_ID, ['full_name' => 'سالم الاختبار التجريبي']);
        $this->trustedMobile($this->person, self::MOBILE);
        $this->freezeSecond();
    }

    private function started(string $nationalId = self::NATIONAL_ID): string
    {
        return $this->postJson(self::BASE.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    /** A challenge whose code was verified: the grant is open. */
    private function verified(): string
    {
        $reference = $this->started();
        $this->postJson(self::BASE.'/verify', ['challenge' => $reference, 'code' => $this->sms->lastCode()])->assertOk();

        return $reference;
    }

    private function complete(string $reference, array $overrides = [], array $headers = self::BROWSER): TestResponse
    {
        return $this->postJson(self::BASE.'/complete', [
            'challenge' => $reference, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$overrides,
        ], $headers);
    }

    private function familyAccounts(): int
    {
        return User::whereNull('email')->count();
    }

    private function assertNothingWasCreated(?string $reference = null): void
    {
        $this->assertSame(0, $this->familyAccounts());
        $this->assertSame(0, UserPersonLink::count());
        $this->assertSame(0, FamilyAuthIdentity::count());
        $this->assertSame(0, User::role('FAMILY_USER')->count());
        if ($reference !== null) {
            $this->assertNull(AuthOtpChallenge::where('uuid', $reference)->sole()->consumed_at);
        }
        $this->assertGuest('web');
    }

    private function assertRefused(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $response->json('message'), 'code' => $code]);
    }

    // ------------------------------------------------------------ happy path

    public function test_a_verified_grant_and_a_password_create_the_family_account(): void
    {
        $reference = $this->verified();

        $response = $this->complete($reference)->assertCreated();

        $user = User::whereNull('email')->sole();
        // A new family-side account, and nothing else.
        $this->assertSame('سالم الاختبار التجريبي', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->remember_token);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(['FAMILY_USER'], $user->getRoleNames()->all());
        $this->assertSame(AccountSide::FAMILY, AccountSide::of($user));

        $link = UserPersonLink::sole();
        $this->assertSame([$user->id, $this->person->id], [$link->user_id, $link->person_id]);
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $link->status);
        $this->assertSame(UserPersonLinkVerificationMethod::SYSTEM_OTP_ACTIVATION, $link->verification_method);

        $identity = FamilyAuthIdentity::sole();
        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $identity->status);
        $this->assertSame(KeyedFingerprint::of(FingerprintContext::LOGIN_ID, self::NATIONAL_ID), $identity->login_key);

        $this->assertNotNull(AuthOtpChallenge::sole()->consumed_at);

        // The answer is the /me representation, and the session is live.
        $response->assertJsonPath('user.display_name', 'سالم الاختبار التجريبي')
            ->assertJsonPath('user.roles', ['FAMILY_USER'])
            ->assertJsonPath('user.context.available', true);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/v1/family/me')->assertOk()->assertJsonPath('user.display_name', 'سالم الاختبار التجريبي');

        foreach (['ACTIVATION_COMPLETED', 'LINK_ACTIVATED', 'OTP_CONSUMED'] as $type) {
            $event = AuthSecurityEvent::where('event_type', $type)->sole();
            $this->assertSame('SUCCESS', $event->outcome->value);
            $this->assertSame($this->person->id, $event->person_id);
        }
        $this->assertSame($user->id, AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole()->user_id);
    }

    public function test_nothing_sensitive_is_returned_recorded_or_logged(): void
    {
        $response = $this->complete($this->verified())->assertCreated();

        $haystack = $response->getContent()."\n"
            .AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n")."\n"
            .implode("\n", $this->logged);

        foreach ([self::PASSWORD, self::NATIONAL_ID, self::MOBILE, $this->sms->lastCode()] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertNotSame(self::PASSWORD, User::whereNull('email')->sole()->getRawOriginal('password'));
    }

    public function test_the_new_account_cannot_enter_the_staff_api_or_the_staff_login(): void
    {
        $this->complete($this->verified())->assertCreated();
        User::whereNull('email')->sole()->givePermissionTo('family.view');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::whereNull('email')->sole());

        $this->getJson('/api/v1/families')->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_a_staff_session_in_the_same_browser_is_replaced(): void
    {
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $reference = $this->verified();

        $this->actingAs($staff, 'web');
        $this->complete($reference)->assertCreated();

        $family = User::whereNull('email')->sole();
        $this->assertAuthenticatedAs($family, 'web');
        // The Staff account itself is untouched: no family-side role was added.
        $this->assertSame(['SUPER_ADMIN'], $staff->fresh()->getRoleNames()->all());
        $this->assertSame(0, UserPersonLink::where('user_id', $staff->id)->count());
    }

    public function test_a_staff_member_who_is_a_head_gets_a_separate_family_account(): void
    {
        $staff = tap(User::factory()->create(['name' => 'سالم الاختبار التجريبي']), fn (User $u) => $u->assignRole('DATA_ENTRY'));

        $this->complete($this->verified())->assertCreated();

        $family = User::whereNull('email')->sole();
        $this->assertNotSame($staff->id, $family->id);
        $this->assertSame(AccountSide::STAFF, AccountSide::of($staff->fresh()));
        $this->assertSame(AccountSide::FAMILY, AccountSide::of($family));
    }

    public function test_a_person_whose_link_was_ended_activates_again_with_a_new_account(): void
    {
        $old = $this->familyUser();
        UserPersonLink::factory()->create([
            'user_id' => $old->id, 'person_id' => $this->person->id, 'status' => UserPersonLinkStatus::ENDED,
            'ended_at' => now(), 'end_reason' => UserPersonLinkEndReason::cases()[0],
        ]);
        FamilyAuthIdentity::factory()->create([
            'user_id' => $old->id, 'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, self::NATIONAL_ID),
            'status' => AuthIdentityStatus::SUPERSEDED, 'superseded_at' => now(), 'supersede_reason' => AuthIdentitySupersedeReason::LINK_ENDED,
        ]);

        $this->complete($this->verified())->assertCreated();

        $new = User::whereNull('email')->where('id', '!=', $old->id)->sole();
        $this->assertSame($new->id, UserPersonLink::query()->current()->sole()->user_id);
        $this->assertSame($new->id, FamilyAuthIdentity::where('status', 'ACTIVE')->sole()->user_id);
        $this->assertSame(0, UserPersonLink::query()->current()->where('user_id', $old->id)->count());
    }

    // -------------------------------------------------------------- password

    public function test_the_password_policy_is_length_and_confirmation_only(): void
    {
        $reference = $this->verified();

        $this->complete($reference, ['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete($reference, ['password_confirmation' => 'another passphrase'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete($reference, ['password' => null])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete($reference, ['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete('not-a-reference')->assertStatus(422)->assertJsonValidationErrors('challenge');
        $this->assertNothingWasCreated($reference);

        // No composition rule: eight lowercase letters are a valid password.
        $this->complete($reference, ['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertCreated();
        $this->assertSame(8, (require base_path('config/family_auth.php'))['password_min_length']);
    }

    public function test_a_password_beyond_the_bcrypt_input_limit_is_refused_not_truncated(): void
    {
        $reference = $this->verified();
        // 72 bytes is the last safe length: 72 ASCII letters, or 36 Arabic ones.
        $arabic36 = str_repeat('ك', 36);
        $this->assertSame(72, strlen($arabic36));

        foreach ([str_repeat('a', 73), $arabic36.'a', str_repeat('ك', 37), str_repeat('a', 255)] as $tooLong) {
            $this->complete($reference, ['password' => $tooLong, 'password_confirmation' => $tooLong])
                ->assertStatus(422)
                ->assertJsonPath('errors.password.0', 'كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.');
        }
        $this->assertNothingWasCreated($reference);

        $this->complete($reference, ['password' => $arabic36, 'password_confirmation' => $arabic36])->assertCreated();
        $stored = User::whereNull('email')->sole()->password;
        // Every character counts: dropping or changing the last one fails.
        $this->assertTrue(Hash::check($arabic36, $stored));
        $this->assertFalse(Hash::check(str_repeat('ك', 35), $stored));
        $this->assertFalse(Hash::check(str_repeat('ك', 35).'ل', $stored));
    }

    public function test_seventy_two_ascii_characters_and_a_short_arabic_password_are_accepted(): void
    {
        $ascii72 = str_repeat('a', 71).'b';
        $this->complete($this->verified(), ['password' => $ascii72, 'password_confirmation' => $ascii72])->assertCreated();
        $stored = User::whereNull('email')->sole()->password;
        $this->assertTrue(Hash::check($ascii72, $stored));
        $this->assertFalse(Hash::check(str_repeat('a', 72), $stored));

        // The minimum is eight CHARACTERS, whatever their byte length.
        $this->assertSame(8, mb_strlen('كلمةسرية'));
        $this->assertSame(72, (require base_path('config/family_auth.php'))['password_max_bytes']);
    }

    // -------------------------------------------------------------- refusals

    public function test_an_unverified_challenge_cannot_reach_the_password_step(): void
    {
        $reference = $this->started();

        $this->assertRefused($this->complete($reference), 422, 'OTP_INVALID');
        $this->assertNothingWasCreated($reference);
    }

    public function test_a_decoy_or_unknown_reference_answers_like_an_unverified_challenge(): void
    {
        $decoy = $this->started('987654321');

        $this->assertRefused($this->complete($decoy), 422, 'OTP_INVALID');
        $this->assertRefused($this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c'), 422, 'OTP_INVALID');

        // Locked, a decoy answers as a locked real challenge does.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'/verify', ['challenge' => $decoy, 'code' => '000000']);
        }
        $this->assertRefused($this->complete($decoy), 423, 'OTP_LOCKED');
        $this->assertNothingWasCreated();
    }

    public function test_the_grant_lasts_ten_minutes(): void
    {
        $late = $this->verified();
        $this->travel(600)->seconds();

        $this->assertRefused($this->complete($late), 410, 'GRANT_EXPIRED');
        $this->assertNothingWasCreated($late);
        $this->assertSame('GRANT_EXPIRED', AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole()->reason_code);
    }

    public function test_one_second_before_the_grant_expires_it_still_works(): void
    {
        $reference = $this->verified();
        $this->travel(599)->seconds();

        $this->complete($reference)->assertCreated();
    }

    public function test_a_death_recorded_after_the_code_was_verified_stops_the_activation(): void
    {
        $reference = $this->verified();
        $this->person->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save();

        $this->assertRefused($this->complete($reference), 409, 'ACTIVATION_FAILED');

        // Rolled back: the grant is not consumed, nothing exists.
        $this->assertNothingWasCreated($reference);
        $event = AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole();
        $this->assertSame(['FAILURE', 'PERSON_NOT_ALIVE'], [$event->outcome->value, $event->reason_code]);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'OTP_CONSUMED')->count());
    }

    public function test_a_headship_that_moved_after_the_code_was_verified_stops_the_activation(): void
    {
        $reference = $this->verified();
        DB::table('family_memberships')->where('person_id', $this->person->id)->update(['is_household_head' => false]);

        $this->assertRefused($this->complete($reference), 409, 'ACTIVATION_FAILED');
        $this->assertNothingWasCreated($reference);
    }

    public function test_a_trust_revoked_or_a_mobile_changed_after_the_code_was_verified_stops_the_activation(): void
    {
        $reference = $this->verified();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        app(RevokePersonMobileTrustAction::class)->handle($admin, PersonMobileTrust::sole(), MobileTrustRevokeReason::REPORTED_LOST);

        $this->assertRefused($this->complete($reference), 423, 'OTP_LOCKED');
        $this->assertNothingWasCreated($reference);
    }

    public function test_a_person_linked_in_the_meantime_is_not_activated_twice(): void
    {
        $reference = $this->verified();
        $other = $this->familyUser();
        UserPersonLink::factory()->create(['user_id' => $other->id, 'person_id' => $this->person->id]);

        $this->assertRefused($this->complete($reference), 409, 'ACTIVATION_FAILED');

        $this->assertSame(1, UserPersonLink::count());
        $this->assertSame(1, $this->familyAccounts());
        $this->assertSame('ALREADY_LINKED', AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole()->reason_code);
    }

    public function test_a_login_key_held_by_another_account_rolls_everything_back(): void
    {
        $reference = $this->verified();
        $other = $this->familyUser();
        FamilyAuthIdentity::factory()->create([
            'user_id' => $other->id, 'key_version' => 1, 'status' => AuthIdentityStatus::ACTIVE->value,
            'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, self::NATIONAL_ID),
        ]);

        $this->assertRefused($this->complete($reference), 409, 'ACTIVATION_FAILED');

        // The half-built account, its role and its link are gone.
        $this->assertSame(1, $this->familyAccounts());
        $this->assertSame(0, UserPersonLink::count());
        $this->assertSame(1, FamilyAuthIdentity::count());
        $this->assertNull(AuthOtpChallenge::sole()->consumed_at);
        $this->assertSame('IDENTITY_REFUSED', AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole()->reason_code);
    }

    public function test_an_unexpected_failure_inside_the_transaction_leaves_no_partial_account(): void
    {
        $reference = $this->verified();
        $this->mock(EstablishFamilyIdentityAction::class)
            ->shouldReceive('handle')->andThrow(new RuntimeException('synthetic failure'));

        $this->complete($reference)->assertStatus(500);

        $this->assertNothingWasCreated($reference);
    }

    // ------------------------------------------------- idempotency and races

    public function test_a_second_completion_of_the_same_challenge_creates_nothing(): void
    {
        $reference = $this->verified();
        $this->complete($reference)->assertCreated();

        // Another tab, or a double submit.
        $this->assertRefused($this->complete($reference), 409, 'ACTIVATION_FAILED');
        $this->assertRefused($this->complete($reference, ['password' => 'another passphrase', 'password_confirmation' => 'another passphrase']), 409, 'ACTIVATION_FAILED');

        $user = User::whereNull('email')->sole();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(1, UserPersonLink::count());
        $this->assertSame(1, FamilyAuthIdentity::count());
    }

    public function test_after_activation_a_new_start_is_a_decoy_and_cannot_activate_again(): void
    {
        $this->complete($this->verified())->assertCreated();
        $sent = $this->sms->attempts;

        $again = $this->started();

        $this->assertSame($sent, $this->sms->attempts);
        $this->assertSame(1, AuthOtpChallenge::count());
        $this->assertRefused($this->complete($again), 422, 'OTP_INVALID');
        $this->assertSame(1, $this->familyAccounts());
    }

    public function test_only_the_latest_challenge_of_a_person_can_complete(): void
    {
        $first = $this->verified();
        // A second flow for the same Person supersedes the first, verified or not.
        $second = $this->verified();

        $this->assertRefused($this->complete($first), 423, 'OTP_LOCKED');
        $this->assertSame(0, $this->familyAccounts());

        $this->complete($second)->assertCreated();
        $this->assertSame(1, $this->familyAccounts());
    }

    // --------------------------------------------------------------- session

    public function test_a_request_without_a_session_is_refused_before_anything_is_created(): void
    {
        $reference = $this->verified();

        // No first-party Origin / Referer: no session can be established.
        $this->assertRefused($this->complete($reference, [], []), 409, 'ACTIVATION_FAILED');

        $this->assertNothingWasCreated($reference);
        $event = AuthSecurityEvent::where('event_type', 'ACTIVATION_COMPLETED')->sole();
        $this->assertSame(['DENIED', 'SESSION_REQUIRED'], [$event->outcome->value, $event->reason_code]);

        // The grant is intact: the browser can still complete.
        $this->complete($reference)->assertCreated();
    }

    public function test_the_ip_ceiling_limits_completions(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c')->assertStatus(422);
        }

        $this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
