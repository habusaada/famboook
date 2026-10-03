<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\RevokePersonMobileTrustAction;
use App\Contracts\SmsSender;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\OtpPurpose;
use App\Enums\UserPersonLinkStatus;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\ChallengeDecoys;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1G: the public password reset steps before the new password (docs/11
 * §30a) — start, verify, resend — with the same anti-enumeration contract as
 * activation: a real PASSWORD_RESET challenge and a decoy answer alike, and
 * neither is interchangeable with an ACTIVATION reference. Codes are read
 * from the fake SMS sender only. Synthetic data only.
 */
class FamilyPasswordResetStartTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const NATIONAL_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    private const MOBILE = '0591234567';

    private FakeSmsSender $sms;

    /** @var array<string, mixed> */
    private array $head;

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
            'family_auth.activation.min_response_ms' => 0,
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        // An activated account with a TRUSTED mobile: the one that may reset.
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->trustedMobile($this->head['person'], self::MOBILE);
        $this->freezeSecond();
    }

    private function start(string $nationalId = self::NATIONAL_ID): TestResponse
    {
        return $this->postJson(self::RESET.'/start', ['national_id' => $nationalId]);
    }

    private function reference(string $kind): string
    {
        return $this->start($kind === 'real' ? self::NATIONAL_ID : self::UNKNOWN_ID)->assertOk()->json('challenge');
    }

    private function verify(string $reference, string $code = '000000', string $base = self::RESET): TestResponse
    {
        return $this->postJson($base.'/verify', ['challenge' => $reference, 'code' => $code]);
    }

    private function resend(string $reference, string $base = self::RESET): TestResponse
    {
        return $this->postJson($base.'/resend', ['challenge' => $reference]);
    }

    private function wrongCode(): string
    {
        return $this->sms->lastCode() === '000000' ? '111111' : '000000';
    }

    private function assertRefused(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertSame(['code', 'message'], collect($response->json())->except('retry_after_seconds')->keys()->sort()->values()->all());
    }

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return ['real challenge' => ['real'], 'decoy' => ['decoy']];
    }

    // ------------------------------------------------------------------ start

    public function test_an_activated_account_gets_a_real_reset_challenge_and_one_sms(): void
    {
        $response = $this->start()->assertOk();

        $response->assertExactJson([
            'challenge' => $response->json('challenge'),
            'resend_after_seconds' => 60,
            'expires_in_seconds' => 300,
            'can_resend' => true,
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $challenge = AuthOtpChallenge::sole();
        $this->assertSame($response->json('challenge'), $challenge->uuid);
        $this->assertSame(OtpPurpose::PASSWORD_RESET, $challenge->purpose);
        // Bound to the Person AND the account.
        $this->assertSame([$this->head['person']->id, $this->head['user']->id], [$challenge->person_id, $challenge->user_id]);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);

        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->sole();
        $this->assertSame(['SUCCESS', $this->head['user']->id, $challenge->uuid], [$event->outcome->value, $event->user_id, $event->otp_challenge_uuid]);
    }

    public function test_arabic_digits_are_accepted_and_a_malformed_identifier_is_a_validation_error(): void
    {
        $this->start('١٢٣٤٥٦٧٨٩')->assertOk();
        $this->assertCount(1, $this->sms->sent);

        foreach (['', '12345678', '12345678a'] as $value) {
            $this->start($value)->assertStatus(422)->assertJsonValidationErrors('national_id');
        }
    }

    /**
     * Every denied situation, as [arrange, recorded reason].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function denials(): array
    {
        return [
            'unknown identifier' => ['unknown', 'UNKNOWN_IDENTIFIER'],
            'registered person never activated' => ['not-activated', 'UNKNOWN_IDENTIFIER'],
            'suspended identity' => ['identity-suspended', 'UNKNOWN_IDENTIFIER'],
            'superseded identity' => ['identity-superseded', 'UNKNOWN_IDENTIFIER'],
            'inactive user' => ['user-inactive', 'USER_INACTIVE'],
            'staff role added (mixed account)' => ['mixed', 'NOT_FAMILY_SIDE'],
            'suspended link' => ['link-suspended', 'LINK_SUSPENDED'],
            'ended link' => ['link-ended', 'NO_LINK'],
            'deceased person' => ['deceased', 'PERSON_NOT_ALIVE'],
            'inactive person' => ['person-inactive', 'PERSON_INACTIVE'],
            'registry national id no longer matches' => ['id-changed', 'IDENTITY_MISMATCH'],
            'headship moved' => ['non-head', 'NOT_HOUSEHOLD_HEAD'],
            'inactive family' => ['family-inactive', 'FAMILY_NOT_ACTIVE'],
            'deleted family' => ['family-deleted', 'FAMILY_DELETED'],
            'no mobile' => ['no-mobile', 'TRUST_NOT_CURRENT'],
            'stale mobile trust' => ['stale', 'TRUST_NOT_CURRENT'],
            'revoked mobile trust' => ['revoked', 'TRUST_NOT_CURRENT'],
            'sms ceiling reached' => ['throttled', 'THROTTLED'],
        ];
    }

    #[DataProvider('denials')]
    public function test_a_denied_start_answers_exactly_like_an_eligible_one(string $case, string $reason): void
    {
        ['user' => $user, 'person' => $person, 'family' => $family, 'membership' => $membership, 'link' => $link, 'identity' => $identity] = $this->head;
        $id = self::NATIONAL_ID;
        match ($case) {
            'unknown' => $id = self::UNKNOWN_ID,
            'not-activated' => (function () use (&$id) {
                [$other] = $this->eligibleHead($id = '444444444');
                $this->trustedMobile($other, '0597777777');
            })(),
            'identity-suspended' => $identity->forceFill(['status' => 'SUSPENDED'])->save(),
            'identity-superseded' => $identity->forceFill(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'supersede_reason' => 'LINK_ENDED'])->save(),
            'user-inactive' => $user->forceFill(['is_active' => false])->save(),
            'mixed' => $user->assignRole('ADMINISTRATOR'),
            'link-suspended' => $link->forceFill(['status' => UserPersonLinkStatus::SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => 'ADMINISTRATIVE'])->save(),
            'link-ended' => $link->forceFill(['status' => UserPersonLinkStatus::ENDED, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save(),
            'deceased' => $person->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save(),
            'person-inactive' => $person->forceFill(['is_active' => false])->save(),
            'id-changed' => $person->forceFill(['national_id' => '222222222'])->saveQuietly(),
            'non-head' => $membership->forceFill(['is_household_head' => false])->save(),
            'family-inactive' => $family->forceFill(['status' => collect(FamilyStatus::cases())->first(fn ($s) => $s !== FamilyStatus::ACTIVE)])->save(),
            'family-deleted' => $family->delete(),
            'no-mobile' => $person->forceFill(['mobile' => null])->saveQuietly(),
            'stale' => PersonMobileTrust::where('person_id', $person->id)->update(['status' => 'STALE', 'stale_at' => now()]),
            'revoked' => PersonMobileTrust::where('person_id', $person->id)->update(['status' => 'REVOKED', 'revoked_by' => User::factory()->create()->id, 'revoked_at' => now(), 'revoke_reason' => 'ADMINISTRATIVE']),
            'throttled' => config(['family_auth.throttle.person.hour' => 0]),
        };

        $response = $this->start($id)->assertOk();

        // The same body as an eligible start, to the key.
        $response->assertExactJson([
            'challenge' => $response->json('challenge'),
            'resend_after_seconds' => 60,
            'expires_in_seconds' => 300,
            'can_resend' => true,
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Nothing real happened: no SMS and no OTP row.
        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, AuthOtpChallenge::count());
        $decoys = app(ChallengeDecoys::class);
        $this->assertNotNull($decoys->state(OtpPurpose::PASSWORD_RESET, $response->json('challenge')));
        $this->assertNull($decoys->state(OtpPurpose::ACTIVATION, $response->json('challenge')));

        // The reason is recorded, never returned.
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->sole();
        $this->assertSame(['DENIED', $reason], [$event->outcome->value, $event->reason_code]);
        $this->assertStringNotContainsString($reason, $response->getContent());
    }

    public function test_a_failed_delivery_still_answers_generically(): void
    {
        $this->sms->failing = true;

        $response = $this->start()->assertOk();

        $this->assertSame(['challenge', 'resend_after_seconds', 'expires_in_seconds', 'can_resend'], array_keys($response->json()));
        $this->assertSame($response->json('challenge'), AuthOtpChallenge::sole()->uuid);
    }

    public function test_a_denied_start_kills_the_earlier_reset_code_of_that_account(): void
    {
        $first = $this->start()->json('challenge');
        $code = $this->sms->lastCode();

        $this->head['membership']->forceFill(['is_household_head' => false])->save();
        $second = $this->start()->assertOk()->json('challenge');

        $this->assertNotSame($first, $second);
        $this->assertRefused($this->verify($first, $code), 423, 'OTP_LOCKED');
    }

    public function test_the_raw_national_id_is_never_logged_recorded_or_used_as_a_key(): void
    {
        $this->start(self::NATIONAL_ID)->assertOk();
        $this->start(self::UNKNOWN_ID)->assertOk();

        $events = AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n");
        $storage = new ReflectionProperty(Cache::getStore(), 'storage');
        $keys = implode("\n", array_keys($storage->getValue(Cache::getStore())));
        $this->assertStringContainsString('family-password-reset|start-identifier|', $keys);

        foreach ([self::NATIONAL_ID, self::UNKNOWN_ID, self::MOBILE] as $raw) {
            $this->assertStringNotContainsString($raw, $events);
            $this->assertStringNotContainsString($raw, $keys);
            $this->assertStringNotContainsString($raw, implode("\n", $this->logged));
        }
    }

    // ------------------------------------------------------------------ gates

    public function test_while_password_reset_is_disabled_nothing_happens(): void
    {
        config(['family_auth.password_reset_enabled' => false]);
        $reference = (string) Str::uuid();

        foreach ([
            $this->start(),
            $this->verify($reference),
            $this->resend($reference),
            $this->postJson(self::RESET.'/complete', []),
        ] as $response) {
            $response->assertStatus(503)->assertExactJson(['message' => 'الخدمة غير متاحة حاليًا.', 'code' => 'PASSWORD_RESET_UNAVAILABLE']);
        }

        $this->assertSame(0, AuthSecurityEvent::count());
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, $this->sms->attempts);
        $this->assertNull(app(ChallengeDecoys::class)->state(OtpPurpose::PASSWORD_RESET, $reference));
    }

    public function test_the_gate_is_off_by_default_and_independent_of_the_other_two(): void
    {
        $this->assertFalse((require base_path('config/family_auth.php'))['password_reset_enabled']);

        // Reset on, activation and login off.
        config(['family_auth.activation_enabled' => false, 'family_auth.login_enabled' => false]);
        $this->start()->assertOk();
        $this->postJson(self::ACTIVATION.'/start', ['national_id' => self::NATIONAL_ID])->assertStatus(503)->assertJsonPath('code', 'ACTIVATION_UNAVAILABLE');
        $this->postJson('/api/v1/family/auth/login', ['national_id' => self::NATIONAL_ID, 'password' => 'x'])->assertStatus(503)->assertJsonPath('code', 'FAMILY_AUTH_UNAVAILABLE');
    }

    public function test_without_the_fingerprint_key_reset_is_unavailable_for_everyone(): void
    {
        config(['family_auth.fingerprint.key' => null]);

        $this->assertRefused($this->start(self::NATIONAL_ID), 503, 'PASSWORD_RESET_UNAVAILABLE');
        $this->assertRefused($this->start(self::UNKNOWN_ID), 503, 'PASSWORD_RESET_UNAVAILABLE');
        $this->assertSame(0, $this->sms->attempts);
    }

    #[DataProvider('kinds')]
    public function test_the_identifier_ceiling_is_separate_from_activation(string $kind): void
    {
        $id = $kind === 'real' ? self::NATIONAL_ID : self::UNKNOWN_ID;
        for ($i = 0; $i < 5; $i++) {
            $this->start($id)->assertOk();
        }
        $this->assertRefused($this->start($id), 429, 'TOO_MANY_REQUESTS');

        // Activation keeps its own counter for the same identifier.
        $this->postJson(self::ACTIVATION.'/start', ['national_id' => $id])->assertOk();
    }

    public function test_the_ip_ceiling_and_its_configuration(): void
    {
        $this->assertSame([
            'start_ip_minute' => 10, 'start_ip_hour' => 30, 'start_identifier_hour' => 5,
            'verify_ip_minute' => 30, 'resend_ip_minute' => 10, 'complete_ip_minute' => 10,
        ], (require base_path('config/family_auth.php'))['password_reset']['limits']);

        for ($i = 0; $i < 10; $i++) {
            $this->start('20000000'.$i)->assertOk();
        }
        $this->assertRefused($this->start('300000000'), 429, 'TOO_MANY_REQUESTS');
    }

    public function test_start_verify_and_resend_wait_out_the_floor(): void
    {
        Sleep::fake();
        config(['family_auth.activation.min_response_ms' => 60_000]);

        $real = $this->start(self::NATIONAL_ID)->json('challenge');
        $decoy = $this->start(self::UNKNOWN_ID)->json('challenge');
        Sleep::assertSleptTimes(2);

        // Since PWA-1I verify waits too (AuthResponseFloorTest).
        $this->verify($real, $this->wrongCode());
        Sleep::assertSleptTimes(3);

        $this->resend($real)->assertStatus(429);
        $this->resend($decoy)->assertStatus(429);
        Sleep::assertSleptTimes(5);
    }

    // ------------------------------------------- verify / resend: real = decoy

    #[DataProvider('kinds')]
    public function test_wrong_codes_are_counted_and_the_fifth_locks(string $kind): void
    {
        $reference = $this->reference($kind);

        for ($i = 0; $i < 4; $i++) {
            $this->assertRefused($this->verify($reference, $this->wrongCode()), 422, 'OTP_INVALID');
        }
        $this->assertRefused($this->verify($reference, $this->wrongCode()), 423, 'OTP_LOCKED');
        $this->assertRefused($this->verify($reference, $this->sms->lastCode() ?? '000000'), 423, 'OTP_LOCKED');
        $this->assertRefused($this->resend($reference), 423, 'OTP_LOCKED');
    }

    #[DataProvider('kinds')]
    public function test_a_code_expires_after_five_minutes(string $kind): void
    {
        $reference = $this->reference($kind);
        $this->travel(300)->seconds();

        $this->assertRefused($this->verify($reference, $this->sms->lastCode() ?? '000000'), 410, 'OTP_EXPIRED');
        $this->assertRefused($this->resend($reference), 410, 'OTP_EXPIRED');
    }

    #[DataProvider('kinds')]
    public function test_resend_honours_the_cooldown_and_the_three_sends(string $kind): void
    {
        $reference = $this->reference($kind);

        $this->travel(20)->seconds();
        $early = $this->resend($reference);
        $this->assertRefused($early, 429, 'OTP_COOLDOWN');
        $this->assertSame(40, $early->json('retry_after_seconds'));

        $this->travel(40)->seconds();
        $this->resend($reference)->assertOk()->assertExactJson(['resend_after_seconds' => 60, 'expires_in_seconds' => 300, 'can_resend' => true]);
        $this->travel(61)->seconds();
        $this->resend($reference)->assertOk()->assertExactJson(['resend_after_seconds' => 60, 'expires_in_seconds' => 300, 'can_resend' => false]);
        $this->travel(61)->seconds();
        $this->assertRefused($this->resend($reference), 429, 'OTP_SEND_LIMIT');

        $this->assertSame($kind === 'real' ? 3 : 0, $this->sms->attempts);
    }

    #[DataProvider('kinds')]
    public function test_a_new_start_makes_the_previous_reference_unusable(string $kind): void
    {
        $first = $this->reference($kind);
        $second = $this->reference($kind);

        $this->assertNotSame($first, $second);
        $this->assertRefused($this->verify($first), 423, 'OTP_LOCKED');
        $this->assertRefused($this->verify($second, $this->wrongCode()), 422, 'OTP_INVALID');
    }

    #[DataProvider('kinds')]
    public function test_a_reference_is_forgotten_after_an_hour(string $kind): void
    {
        $reference = $this->reference($kind);
        $this->travel(ChallengeDecoys::REFERENCE_TTL)->seconds();

        $this->assertRefused($this->verify($reference), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend($reference), 422, 'OTP_INVALID');
    }

    #[DataProvider('kinds')]
    public function test_a_malformed_code_is_a_field_error_and_not_an_attempt(string $kind): void
    {
        $reference = $this->reference($kind);

        foreach (['', '12345', 'abcdef'] as $code) {
            $this->verify($reference, $code)->assertStatus(422)->assertJsonValidationErrors('code');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->assertRefused($this->verify($reference, $this->wrongCode()), 422, 'OTP_INVALID');
        }
    }

    // ---------------------------------------------- purposes never interchange

    #[DataProvider('kinds')]
    public function test_a_reset_reference_is_unknown_to_activation(string $kind): void
    {
        $reference = $this->reference($kind);
        $code = $this->sms->lastCode() ?? '000000';

        $this->assertRefused($this->verify($reference, $code, self::ACTIVATION), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend($reference, self::ACTIVATION), 422, 'OTP_INVALID');
        $this->postJson(self::ACTIVATION.'/complete', ['challenge' => $reference, 'password' => 'synthetic-pass', 'password_confirmation' => 'synthetic-pass'], ['Referer' => 'http://localhost:3000'])
            ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        // Untouched by the foreign endpoint: no attempt was counted, and it still works here.
        if ($kind === 'real') {
            $this->assertSame(0, AuthOtpChallenge::sole()->attempts);
            $this->verify($reference, $code)->assertOk();
        } else {
            $this->assertSame(0, app(ChallengeDecoys::class)->state(OtpPurpose::PASSWORD_RESET, $reference)['attempts']);
        }
    }

    #[DataProvider('kinds')]
    public function test_an_activation_reference_is_unknown_to_reset(string $kind): void
    {
        // An activation flow for another Person (real) or an unknown identifier (decoy).
        if ($kind === 'real') {
            [$other] = $this->eligibleHead('444444444');
            $this->trustedMobile($other, '0597777777');
        }
        $reference = $this->startActivationChallenge($kind === 'real' ? '444444444' : self::UNKNOWN_ID);
        $code = $this->sms->lastCode() ?? '000000';

        $this->assertRefused($this->verify($reference, $code), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend($reference), 422, 'OTP_INVALID');

        if ($kind === 'real') {
            $this->assertSame([0, null], [AuthOtpChallenge::sole()->attempts, AuthOtpChallenge::sole()->verified_at]);
        }
    }

    public function test_activation_and_reset_decoys_of_one_identifier_do_not_supersede_each_other(): void
    {
        $activation = $this->startActivationChallenge(self::UNKNOWN_ID);
        $reset = $this->start(self::UNKNOWN_ID)->json('challenge');

        // Each is still open for its own purpose.
        $this->assertRefused($this->verify($activation, '000000', self::ACTIVATION), 422, 'OTP_INVALID');
        $this->assertRefused($this->verify($reset), 422, 'OTP_INVALID');
    }

    // ------------------------------------------------------------- real only

    public function test_the_correct_code_opens_the_grant_and_changes_nothing(): void
    {
        $reference = $this->reference('real');
        $password = $this->head['user']->password;

        $this->verify($reference, $this->sms->lastCode())->assertOk()
            ->assertExactJson(['verified' => true, 'grant_expires_in_seconds' => 600]);

        $challenge = AuthOtpChallenge::sole();
        $this->assertNotNull($challenge->verified_at);
        $this->assertNull($challenge->consumed_at);
        $this->assertSame($password, $this->head['user']->fresh()->password);
        $this->assertGuest('web');
    }

    public function test_a_resend_delivers_a_new_code_and_kills_the_old_one(): void
    {
        $reference = $this->reference('real');
        $old = $this->sms->lastCode();

        $this->travel(61)->seconds();
        $this->resend($reference)->assertOk();
        $new = $this->sms->lastCode();

        if ($old !== $new) {
            $this->assertRefused($this->verify($reference, $old), 422, 'OTP_INVALID');
        }
        $this->verify($reference, $new)->assertOk();
    }

    public function test_a_trust_revoked_or_a_mobile_changed_after_the_start_kills_the_code(): void
    {
        $reference = $this->reference('real');
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        app(RevokePersonMobileTrustAction::class)->handle($admin, PersonMobileTrust::sole(), MobileTrustRevokeReason::REPORTED_LOST);

        $this->assertRefused($this->verify($reference, $this->sms->lastCode()), 423, 'OTP_LOCKED');
        $this->assertRefused($this->resend($reference), 423, 'OTP_LOCKED');
    }

    public function test_reset_and_activation_share_the_per_person_send_ceiling(): void
    {
        // Three reset sends, then two more on a second reset challenge: five an hour.
        $first = $this->reference('real');
        foreach ([61, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->resend($first)->assertOk();
        }
        $second = $this->reference('real');
        $this->travel(61)->seconds();
        $this->resend($second)->assertOk();

        $this->travel(61)->seconds();
        $this->assertRefused($this->resend($second), 429, 'OTP_SEND_LIMIT');
        $this->assertSame(5, $this->sms->attempts);
    }
}
