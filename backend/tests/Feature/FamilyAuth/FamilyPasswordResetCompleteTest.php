<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\RevokePersonMobileTrustAction;
use App\Contracts\SmsSender;
use App\Enums\LifeStatus;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileTrustStatus;
use App\Enums\UserPersonLinkStatus;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyAuthIdentity;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1G: the password reset completion (docs/11 §30a) — the one transaction
 * that changes the password and ends every earlier session, and the new
 * session after it. Codes are read from the fake SMS sender only. Synthetic
 * data only.
 */
class FamilyPasswordResetCompleteTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const NATIONAL_ID = '123456789';

    private const MOBILE = '0591234567';

    /** The password every factory-made account has. Synthetic. */
    private const OLD_PASSWORD = 'password';

    private const NEW_PASSWORD = 'a new synthetic passphrase';

    /** A first-party browser request: the only kind that carries a session. */
    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    private FakeSmsSender $sms;

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
        config([
            'family_auth.password_reset_enabled' => true,
            'family_auth.activation_enabled' => true,
            'family_auth.login_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->user = $this->head['user'];
        $this->trustedMobile($this->head['person'], self::MOBILE);
        $this->freezeSecond();
    }

    private function started(string $nationalId = self::NATIONAL_ID): string
    {
        return $this->postJson(self::RESET.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    /** A reset challenge whose code was verified: the grant is open. */
    private function verified(): string
    {
        $reference = $this->started();
        $this->postJson(self::RESET.'/verify', ['challenge' => $reference, 'code' => $this->sms->lastCode()])->assertOk();

        return $reference;
    }

    private function complete(string $reference, array $overrides = [], array $headers = self::BROWSER, string $base = self::RESET): TestResponse
    {
        return $this->postJson($base.'/complete', [
            'challenge' => $reference, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, ...$overrides,
        ], $headers);
    }

    private function login(string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/family/auth/login', ['national_id' => self::NATIONAL_ID, 'password' => $password], self::BROWSER);
    }

    private function assertRefused(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $response->json('message'), 'code' => $code]);
    }

    /** The old password still works, the grant is intact, nobody is signed in. */
    private function assertNothingChanged(?string $reference = null): void
    {
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));
        if ($reference !== null) {
            $this->assertNull(AuthOtpChallenge::where('uuid', $reference)->sole()->consumed_at);
        }
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'SESSIONS_REVOKED')->count());
        $this->assertGuest('web');
    }

    // ------------------------------------------------------------ happy path

    public function test_a_verified_grant_and_a_new_password_reset_the_password_and_sign_in(): void
    {
        $reference = $this->verified();

        $response = $this->complete($reference)->assertOk();

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertNull($fresh->remember_token);
        $this->assertNotNull(AuthOtpChallenge::sole()->consumed_at);

        // The /me representation, and a live session: no extra login step.
        $response->assertJsonPath('user.display_name', $this->head['person']->full_name)
            ->assertJsonPath('user.roles', ['FAMILY_USER'])
            ->assertJsonPath('user.context.available', true);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($this->user, 'web');
        $this->getJson('/api/v1/family/me')->assertOk();

        foreach (['PASSWORD_RESET_COMPLETED', 'SESSIONS_REVOKED', 'OTP_CONSUMED'] as $type) {
            $event = AuthSecurityEvent::where('event_type', $type)->sole();
            $this->assertSame(['SUCCESS', $this->user->id], [$event->outcome->value, $event->user_id]);
        }

        // Nothing else about the account moved.
        $this->assertSame(1, User::whereNull('email')->count());
        $this->assertSame(UserPersonLinkStatus::ACTIVE, UserPersonLink::sole()->status);
        $this->assertSame('ACTIVE', FamilyAuthIdentity::sole()->status->value);
        $this->assertSame(MobileTrustStatus::TRUSTED, PersonMobileTrust::sole()->status);
    }

    public function test_after_a_reset_the_old_password_fails_and_the_new_one_logs_in(): void
    {
        $this->complete($this->verified())->assertOk();
        $this->postJson('/api/v1/family/auth/logout')->assertNoContent();

        $this->login(self::OLD_PASSWORD)->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->login(self::NEW_PASSWORD)->assertOk();
    }

    public function test_nothing_sensitive_is_returned_recorded_or_logged(): void
    {
        $response = $this->complete($this->verified())->assertOk();

        $haystack = $response->getContent()."\n"
            .AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n")."\n"
            .implode("\n", $this->logged);

        foreach ([self::NEW_PASSWORD, self::NATIONAL_ID, self::MOBILE, $this->sms->lastCode()] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertNotSame(self::NEW_PASSWORD, $this->user->fresh()->getRawOriginal('password'));
    }

    // -------------------------------------------------------------- password

    public function test_the_password_policy_is_the_shared_family_policy(): void
    {
        $reference = $this->verified();
        $tooLong = 'كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.';

        $this->complete($reference, ['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete($reference, ['password_confirmation' => 'another passphrase'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        // bcrypt reads 72 bytes: 37 Arabic letters (74 bytes) and 73 ASCII ones are refused.
        foreach ([str_repeat('ك', 37), str_repeat('a', 73)] as $password) {
            $this->complete($reference, ['password' => $password, 'password_confirmation' => $password])
                ->assertStatus(422)->assertJsonPath('errors.password.0', $tooLong);
        }
        $this->complete('not-a-reference')->assertStatus(422)->assertJsonValidationErrors('challenge');
        $this->assertNothingChanged($reference);

        // Exactly 72 bytes of Arabic is accepted — and every letter of it counts.
        $arabic36 = str_repeat('ك', 35).'ل';
        $this->complete($reference, ['password' => $arabic36, 'password_confirmation' => $arabic36])->assertOk();
        $stored = $this->user->fresh()->password;
        $this->assertTrue(Hash::check($arabic36, $stored));
        $this->assertFalse(Hash::check(str_repeat('ك', 36), $stored));
        $this->assertFalse(Hash::check(str_repeat('ك', 35), $stored));
    }

    public function test_eight_arabic_characters_are_a_valid_password(): void
    {
        $password = 'كلمةسرية';
        $this->assertSame(8, mb_strlen($password));

        $this->complete($this->verified(), ['password' => $password, 'password_confirmation' => $password])->assertOk();

        $this->assertTrue(Hash::check($password, $this->user->fresh()->password));
    }

    // -------------------------------------------------------------- refusals

    public function test_an_unverified_challenge_cannot_set_a_password(): void
    {
        $reference = $this->started();

        $this->assertRefused($this->complete($reference), 422, 'OTP_INVALID');
        $this->assertNothingChanged($reference);
    }

    public function test_a_decoy_or_unknown_reference_answers_like_an_unverified_challenge(): void
    {
        $decoy = $this->started('987654321');

        $this->assertRefused($this->complete($decoy), 422, 'OTP_INVALID');
        $this->assertRefused($this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c'), 422, 'OTP_INVALID');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::RESET.'/verify', ['challenge' => $decoy, 'code' => '000000']);
        }
        $this->assertRefused($this->complete($decoy), 423, 'OTP_LOCKED');
        $this->assertNothingChanged();
    }

    public function test_a_verified_activation_challenge_cannot_reset_a_password(): void
    {
        // Another Person activates: their verified ACTIVATION grant is real and open.
        [$other] = $this->eligibleHead('444444444');
        $this->trustedMobile($other, '0597777777');
        $activation = $this->postJson(self::ACTIVATION.'/start', ['national_id' => '444444444'])->json('challenge');
        $this->postJson(self::ACTIVATION.'/verify', ['challenge' => $activation, 'code' => $this->sms->lastCode()])->assertOk();

        $this->assertRefused($this->complete($activation), 422, 'OTP_INVALID');

        $this->assertNothingChanged();
        $this->assertNull(AuthOtpChallenge::where('uuid', $activation)->sole()->consumed_at);
    }

    public function test_a_verified_reset_challenge_cannot_activate_an_account(): void
    {
        $reference = $this->verified();

        $this->assertRefused($this->complete($reference, base: self::ACTIVATION), 422, 'OTP_INVALID');

        $this->assertSame(1, User::whereNull('email')->count());
        $this->assertNothingChanged($reference);
        // Still good for what it was issued for.
        $this->complete($reference)->assertOk();
    }

    public function test_the_grant_lasts_ten_minutes(): void
    {
        $late = $this->verified();
        $this->travel(600)->seconds();

        $this->assertRefused($this->complete($late), 410, 'GRANT_EXPIRED');
        $this->assertNothingChanged($late);
        $this->assertSame('GRANT_EXPIRED', AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->sole()->reason_code);
    }

    public function test_one_second_before_the_grant_expires_it_still_works(): void
    {
        $reference = $this->verified();
        $this->travel(599)->seconds();

        $this->complete($reference)->assertOk();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function changes(): array
    {
        return [
            'death recorded' => ['deceased', 'PERSON_NOT_ALIVE'],
            'headship moved' => ['non-head', 'NOT_HOUSEHOLD_HEAD'],
            'link suspended' => ['link-suspended', 'LINK_SUSPENDED'],
            'account deactivated' => ['user-inactive', 'USER_INACTIVE'],
            'staff role added' => ['mixed', 'NOT_FAMILY_SIDE'],
            'family deleted' => ['family-deleted', 'FAMILY_DELETED'],
        ];
    }

    #[DataProvider('changes')]
    public function test_an_account_that_lost_its_context_after_the_code_was_verified_is_not_reset(string $case, string $reason): void
    {
        $reference = $this->verified();
        match ($case) {
            'deceased' => $this->head['person']->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save(),
            'non-head' => $this->head['membership']->forceFill(['is_household_head' => false])->save(),
            'link-suspended' => $this->head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => 'ADMINISTRATIVE'])->save(),
            'user-inactive' => $this->user->forceFill(['is_active' => false])->save(),
            'mixed' => $this->user->assignRole('ADMINISTRATOR'),
            'family-deleted' => $this->head['family']->delete(),
        };

        $response = $this->complete($reference);

        $this->assertRefused($response, 409, 'RESET_FAILED');
        $this->assertStringNotContainsString($reason, $response->getContent());
        // Rolled back: old password, grant unconsumed, no session ended.
        $this->assertNothingChanged($reference);
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->sole();
        $this->assertSame(['FAILURE', $reason], [$event->outcome->value, $event->reason_code]);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'OTP_CONSUMED')->count());
    }

    public function test_a_trust_revoked_after_the_code_was_verified_stops_the_reset(): void
    {
        $reference = $this->verified();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        app(RevokePersonMobileTrustAction::class)->handle($admin, PersonMobileTrust::sole(), MobileTrustRevokeReason::REPORTED_LOST);

        $this->assertRefused($this->complete($reference), 423, 'OTP_LOCKED');
        $this->assertNothingChanged($reference);
    }

    public function test_an_unexpected_failure_inside_the_transaction_changes_nothing(): void
    {
        config(['session.driver' => 'database']);
        $reference = $this->verified();
        $this->sessionRowFor($this->user);
        // The password write itself fails, after the grant was consumed.
        User::updating(function () {
            throw new RuntimeException('synthetic failure');
        });

        $this->complete($reference)->assertStatus(500);

        $this->assertNothingChanged($reference);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->where('outcome', 'SUCCESS')->count());
    }

    public function test_a_second_completion_of_the_same_challenge_changes_nothing_more(): void
    {
        $reference = $this->verified();
        $this->complete($reference)->assertOk();

        $this->assertRefused($this->complete($reference, ['password' => 'yet another passphrase', 'password_confirmation' => 'yet another passphrase']), 409, 'RESET_FAILED');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'SESSIONS_REVOKED')->count());
    }

    public function test_only_the_latest_reset_challenge_can_complete(): void
    {
        $first = $this->verified();
        $second = $this->verified();

        $this->assertRefused($this->complete($first), 423, 'OTP_LOCKED');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));

        $this->complete($second)->assertOk();
    }

    // --------------------------------------------------------------- sessions

    public function test_every_earlier_session_is_ended_and_the_completing_browser_gets_a_new_one(): void
    {
        config(['session.driver' => 'database']);
        $reference = $this->verified();
        // Two other devices of this account, and somebody else's session.
        $this->sessionRowFor($this->user);
        $this->sessionRowFor($this->user);
        $other = $this->activatedHead('555555555');
        $this->sessionRowFor($other['user']);
        $old = DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all();
        $this->assertCount(2, $old);

        $this->complete($reference)->assertOk();

        // The old rows are gone; exactly one row remains for the account — the new session.
        $rows = DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all();
        $this->assertCount(1, $rows);
        $this->assertNotContains($rows[0], $old);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other['user']->id)->count());
        $this->assertAuthenticatedAs($this->user, 'web');
        $this->getJson('/api/v1/family/me')->assertOk();
    }

    public function test_a_browser_already_signed_in_as_the_account_stays_signed_in_with_a_new_session(): void
    {
        $reference = $this->verified();
        $this->actingAs($this->user, 'web');
        $this->startSession();
        $before = session()->getId();

        $this->complete($reference)->assertOk();

        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_a_staff_session_in_the_same_browser_is_replaced(): void
    {
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $reference = $this->verified();
        $this->actingAs($staff, 'web');

        $this->complete($reference)->assertOk();

        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
    }

    public function test_a_request_without_a_session_is_refused_before_anything_changes(): void
    {
        $reference = $this->verified();

        $this->assertRefused($this->complete($reference, [], []), 409, 'RESET_FAILED');

        $this->assertNothingChanged($reference);
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->sole();
        $this->assertSame(['DENIED', 'SESSION_REQUIRED'], [$event->outcome->value, $event->reason_code]);

        $this->complete($reference)->assertOk();
    }

    public function test_the_ip_ceiling_limits_completions(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c')->assertStatus(422);
        }

        $this->complete('3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
