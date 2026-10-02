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
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\ChallengeDecoys;
use App\Support\FamilyAuth\OtpChallenges;
use App\Support\FamilyAuth\ResponseFloor;
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
 * PWA-1F: the public activation steps before the password (docs/11 §30a) —
 * start, verify, resend — and their anti-enumeration contract: a real
 * challenge and a decoy answer alike. Codes are read from the fake SMS
 * sender only. Synthetic data only.
 */
class FamilyActivationStartTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const START = '/api/v1/family/auth/activation/start';

    private const VERIFY = '/api/v1/family/auth/activation/verify';

    private const RESEND = '/api/v1/family/auth/activation/resend';

    private const ELIGIBLE_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    private const MOBILE = '0591234567';

    private FakeSmsSender $sms;

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
        $this->freezeSecond();
    }

    /** An eligible, not yet activated household head with a TRUSTED mobile. */
    private function eligible(string $nationalId = self::ELIGIBLE_ID): Person
    {
        [$person] = $this->eligibleHead($nationalId);
        $this->trustedMobile($person, self::MOBILE);

        return $person;
    }

    private function start(string $nationalId = self::ELIGIBLE_ID): TestResponse
    {
        return $this->postJson(self::START, ['national_id' => $nationalId]);
    }

    /** A challenge reference of the given kind, after one start. */
    private function reference(string $kind): string
    {
        if ($kind === 'real') {
            $this->eligible();
        }

        return $this->start($kind === 'real' ? self::ELIGIBLE_ID : self::UNKNOWN_ID)->assertOk()->json('challenge');
    }

    private function verify(string $reference, string $code = '000000'): TestResponse
    {
        return $this->postJson(self::VERIFY, ['challenge' => $reference, 'code' => $code]);
    }

    private function resend(string $reference): TestResponse
    {
        return $this->postJson(self::RESEND, ['challenge' => $reference]);
    }

    /** A code that is certainly not the one that was sent. */
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

    public function test_an_eligible_head_gets_a_real_challenge_and_one_sms(): void
    {
        $person = $this->eligible();
        $users = User::count();

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
        $this->assertSame(OtpPurpose::ACTIVATION, $challenge->purpose);
        $this->assertSame($person->id, $challenge->person_id);
        $this->assertNull($challenge->user_id);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);
        $this->assertSame($users, User::count());

        $event = AuthSecurityEvent::where('event_type', 'ACTIVATION_REQUESTED')->sole();
        $this->assertSame('SUCCESS', $event->outcome->value);
        $this->assertSame($person->id, $event->person_id);
        $this->assertSame($challenge->uuid, $event->otp_challenge_uuid);
    }

    public function test_arabic_and_persian_digits_and_separators_are_accepted(): void
    {
        $this->eligible();

        $this->start('١٢٣-٤٥٦ ۷۸۹')->assertOk();

        $this->assertCount(1, $this->sms->sent);
    }

    public function test_a_malformed_identifier_is_a_plain_validation_error(): void
    {
        foreach (['', '12345678', '1234567890', '12345678a', 'abcdefghi'] as $value) {
            $this->start($value)->assertStatus(422)->assertJsonValidationErrors('national_id');
        }
        $this->postJson(self::START, [])->assertStatus(422)->assertJsonValidationErrors('national_id');
        $this->postJson(self::START, ['national_id' => ['123456789']])->assertStatus(422);

        $this->assertSame(0, AuthSecurityEvent::count());
        $this->assertSame(0, $this->sms->attempts);
    }

    /**
     * Every denied situation, as [arrange, expected event type, expected reason].
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function denials(): array
    {
        return [
            'unknown identifier' => ['unknown', 'ACTIVATION_REQUESTED', 'NOT_FOUND'],
            'deceased head' => ['deceased', 'ELIGIBILITY_DENIED', 'PERSON_NOT_ALIVE'],
            'unknown life status' => ['life-unknown', 'ELIGIBILITY_DENIED', 'PERSON_NOT_ALIVE'],
            'inactive person' => ['inactive', 'ELIGIBILITY_DENIED', 'PERSON_INACTIVE'],
            'soft-deleted person' => ['deleted', 'ACTIVATION_REQUESTED', 'NOT_FOUND'],
            'not the household head' => ['non-head', 'ELIGIBILITY_DENIED', 'NOT_HOUSEHOLD_HEAD'],
            'no active membership' => ['no-membership', 'ELIGIBILITY_DENIED', 'NO_ACTIVE_MEMBERSHIP'],
            'inactive family' => ['family-inactive', 'ELIGIBILITY_DENIED', 'FAMILY_NOT_ACTIVE'],
            'deleted family' => ['family-deleted', 'ELIGIBILITY_DENIED', 'FAMILY_DELETED'],
            'no mobile' => ['no-mobile', 'ELIGIBILITY_DENIED', 'TRUST_NOT_CURRENT'],
            'mobile never verified' => ['untrusted', 'ELIGIBILITY_DENIED', 'TRUST_NOT_CURRENT'],
            'stale mobile trust' => ['stale', 'ELIGIBILITY_DENIED', 'TRUST_NOT_CURRENT'],
            'revoked mobile trust' => ['revoked', 'ELIGIBILITY_DENIED', 'TRUST_NOT_CURRENT'],
            'already activated' => ['activated', 'ELIGIBILITY_DENIED', 'ALREADY_LINKED'],
            'suspended link' => ['suspended', 'ELIGIBILITY_DENIED', 'ALREADY_LINKED'],
            'identifier shared by two persons' => ['duplicate', 'AMBIGUOUS_IDENTITY', null],
            'sms ceiling reached' => ['throttled', 'ELIGIBILITY_DENIED', 'THROTTLED'],
        ];
    }

    private function arrangeDenial(string $case): void
    {
        if ($case === 'unknown') {
            return;
        }
        if (in_array($case, ['activated', 'suspended'], true)) {
            $head = $this->activatedHead(self::ELIGIBLE_ID);
            $this->trustedMobile($head['person'], self::MOBILE);
            if ($case === 'suspended') {
                $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => 'ADMINISTRATIVE'])->save();
            }

            return;
        }

        [$person, $family, $membership] = $this->eligibleHead(self::ELIGIBLE_ID);
        if (! in_array($case, ['no-mobile', 'untrusted'], true)) {
            $this->trustedMobile($person, self::MOBILE);
        }
        match ($case) {
            'deceased' => $person->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save(),
            'life-unknown' => $person->forceFill(['life_status' => LifeStatus::UNKNOWN])->save(),
            'inactive' => $person->forceFill(['is_active' => false])->save(),
            'deleted' => $person->delete(),
            'non-head' => $membership->forceFill(['is_household_head' => false])->save(),
            'no-membership' => $membership->forceFill(['is_active' => false])->save(),
            'family-inactive' => $family->forceFill(['status' => collect(FamilyStatus::cases())->first(fn ($s) => $s !== FamilyStatus::ACTIVE)])->save(),
            'family-deleted' => $family->delete(),
            'no-mobile' => $person->forceFill(['mobile' => null])->save(),
            'untrusted' => $person->forceFill(['mobile' => self::MOBILE])->save(),
            'stale' => PersonMobileTrust::where('person_id', $person->id)->update(['status' => 'STALE', 'stale_at' => now()]),
            'revoked' => PersonMobileTrust::where('person_id', $person->id)->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoke_reason' => 'ADMINISTRATIVE']),
            'duplicate' => Person::factory()->create(['national_id' => self::ELIGIBLE_ID]),
            'throttled' => config(['family_auth.throttle.person.hour' => 0]),
        };
    }

    #[DataProvider('denials')]
    public function test_a_denied_start_answers_exactly_like_an_eligible_one(string $case, string $eventType, ?string $reason): void
    {
        $this->arrangeDenial($case);
        $users = User::count();

        $response = $this->start($case === 'unknown' ? self::UNKNOWN_ID : self::ELIGIBLE_ID)->assertOk();

        // The same body as an eligible start, to the key.
        $response->assertExactJson([
            'challenge' => $response->json('challenge'),
            'resend_after_seconds' => 60,
            'expires_in_seconds' => 300,
            'can_resend' => true,
        ]);
        $this->assertTrue(Str::isUuid($response->json('challenge')));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Nothing real happened: no SMS, no OTP row, no account.
        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame($users, User::count());
        $this->assertNotNull(app(ChallengeDecoys::class)->state(OtpPurpose::ACTIVATION, $response->json('challenge')));
        // Purpose-bound: the same reference does not exist for a password reset.
        $this->assertNull(app(ChallengeDecoys::class)->state(OtpPurpose::PASSWORD_RESET, $response->json('challenge')));

        // The reason is recorded, never returned.
        $event = AuthSecurityEvent::where('event_type', $eventType)->sole();
        $this->assertSame('DENIED', $event->outcome->value);
        $this->assertSame($reason, $event->reason_code);
        if ($reason !== null) {
            $this->assertStringNotContainsString($reason, $response->getContent());
        }
    }

    public function test_a_failed_delivery_still_answers_generically(): void
    {
        $this->eligible();
        $this->sms->failing = true;

        $response = $this->start()->assertOk();

        $this->assertSame(['challenge', 'resend_after_seconds', 'expires_in_seconds', 'can_resend'], array_keys($response->json()));
        $this->assertSame($response->json('challenge'), AuthOtpChallenge::sole()->uuid);
        $this->assertSame(1, $this->sms->attempts);
    }

    public function test_a_denied_start_kills_the_earlier_code_of_that_person(): void
    {
        $person = $this->eligible();
        $first = $this->start()->json('challenge');
        $code = $this->sms->lastCode();

        // The Person stops being eligible; the next start is a decoy.
        $person->forceFill(['is_active' => false])->save();
        $second = $this->start()->assertOk()->json('challenge');

        $this->assertNotSame($first, $second);
        $this->assertRefused($this->verify($first, $code), 423, 'OTP_LOCKED');
    }

    public function test_the_raw_national_id_is_never_logged_recorded_or_used_as_a_key(): void
    {
        $this->eligible();
        $this->start(self::ELIGIBLE_ID)->assertOk();
        $this->start(self::UNKNOWN_ID)->assertOk();

        $events = AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n");
        $storage = new ReflectionProperty(Cache::getStore(), 'storage');
        $keys = implode("\n", array_keys($storage->getValue(Cache::getStore())));
        $this->assertNotSame('', $keys);

        foreach ([self::ELIGIBLE_ID, self::UNKNOWN_ID, self::MOBILE] as $raw) {
            $this->assertStringNotContainsString($raw, $events);
            $this->assertStringNotContainsString($raw, $keys);
            $this->assertStringNotContainsString($raw, implode("\n", $this->logged));
        }
        // The unknown identifier is recorded only as its keyed fingerprint.
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', AuthSecurityEvent::where('reason_code', 'NOT_FOUND')->sole()->login_key);
    }

    // ---------------------------------------------------------------- gates

    public function test_while_activation_is_disabled_nothing_happens(): void
    {
        $this->eligible();
        config(['family_auth.activation_enabled' => false]);
        $reference = (string) Str::uuid();

        foreach ([
            $this->start(),
            $this->verify($reference),
            $this->resend($reference),
            $this->postJson('/api/v1/family/auth/activation/complete', []),
        ] as $response) {
            $this->assertRefused($response, 503, 'ACTIVATION_UNAVAILABLE');
        }

        $this->assertSame(0, AuthSecurityEvent::count());
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, $this->sms->attempts);
        $this->assertNull(app(ChallengeDecoys::class)->state(OtpPurpose::ACTIVATION, $reference));
    }

    public function test_the_gate_is_off_by_default(): void
    {
        $this->assertFalse((require base_path('config/family_auth.php'))['activation_enabled']);
    }

    public function test_without_the_fingerprint_key_activation_is_unavailable_for_everyone(): void
    {
        $this->eligible();
        config(['family_auth.fingerprint.key' => null]);

        $this->assertRefused($this->start(self::ELIGIBLE_ID), 503, 'ACTIVATION_UNAVAILABLE');
        $this->assertRefused($this->start(self::UNKNOWN_ID), 503, 'ACTIVATION_UNAVAILABLE');
        $this->assertSame(0, $this->sms->attempts);
    }

    #[DataProvider('kinds')]
    public function test_the_identifier_ceiling_applies_to_known_and_unknown_identifiers_alike(string $kind): void
    {
        if ($kind === 'real') {
            $this->eligible();
        }
        $id = $kind === 'real' ? self::ELIGIBLE_ID : self::UNKNOWN_ID;

        for ($i = 0; $i < 5; $i++) {
            $this->start($id)->assertOk();
        }
        $this->assertRefused($this->start($id), 429, 'TOO_MANY_REQUESTS');

        // Another identifier is unaffected; the hour passes and it reopens.
        $this->start('111111111')->assertOk();
        $this->travel(3601)->seconds();
        $this->start($id)->assertOk();
    }

    public function test_the_ip_ceiling_limits_starts_across_identifiers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->start('20000000'.$i)->assertOk();
        }

        $this->assertRefused($this->start('300000000'), 429, 'TOO_MANY_REQUESTS');
    }

    public function test_the_ceilings_are_configurable(): void
    {
        $defaults = (require base_path('config/family_auth.php'))['activation'];
        $this->assertSame(400, $defaults['min_response_ms']);
        $this->assertSame([
            'start_ip_minute' => 10, 'start_ip_hour' => 30, 'start_identifier_hour' => 5,
            'verify_ip_minute' => 30, 'resend_ip_minute' => 10, 'complete_ip_minute' => 10,
        ], $defaults['limits']);

        config(['family_auth.activation.limits.start_ip_minute' => 2]);
        $this->start('200000001')->assertOk();
        $this->start('200000002')->assertOk();
        $this->assertRefused($this->start('200000003'), 429, 'TOO_MANY_REQUESTS');
    }

    // ------------------------------------------------- the response-time floor

    public function test_the_floor_helper_owes_only_what_is_left(): void
    {
        $this->assertSame(400, ResponseFloor::remainingMs(0, 400));
        $this->assertSame(150, ResponseFloor::remainingMs(250.4, 400));
        $this->assertSame(0, ResponseFloor::remainingMs(400, 400));
        $this->assertSame(0, ResponseFloor::remainingMs(900, 400));
        $this->assertSame(0, ResponseFloor::remainingMs(10, 0));
        $this->assertSame(400, ResponseFloor::remainingMs(-5, 400));

        config(['family_auth.activation.min_response_ms' => 250]);
        $this->assertSame(250, ResponseFloor::remainingMs(0));
    }

    public function test_start_and_resend_wait_out_the_floor_and_verify_does_not(): void
    {
        Sleep::fake();
        config(['family_auth.activation.min_response_ms' => 60_000]);
        $this->eligible();

        $real = $this->start(self::ELIGIBLE_ID)->assertOk()->json('challenge');
        $decoy = $this->start(self::UNKNOWN_ID)->assertOk()->json('challenge');
        Sleep::assertSleptTimes(2);

        $this->verify($real, $this->wrongCode());
        $this->verify($decoy);
        Sleep::assertSleptTimes(2);

        // A refused resend waits too.
        $this->resend($real)->assertStatus(429);
        $this->resend($decoy)->assertStatus(429);
        Sleep::assertSleptTimes(4);

        config(['family_auth.activation.min_response_ms' => 0]);
        $this->start('111111111')->assertOk();
        Sleep::assertSleptTimes(4);
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
    public function test_a_resend_restarts_the_expiry_but_not_the_attempts(string $kind): void
    {
        $reference = $this->reference($kind);
        $this->assertRefused($this->verify($reference, $this->wrongCode()), 422, 'OTP_INVALID');

        $this->travel(240)->seconds();
        $this->resend($reference)->assertOk();
        $this->travel(240)->seconds();

        // 480 s after the start: alive because the resend restarted the clock.
        for ($i = 0; $i < 3; $i++) {
            $this->assertRefused($this->verify($reference, $this->wrongCode()), 422, 'OTP_INVALID');
        }
        // The fifth attempt overall locks.
        $this->assertRefused($this->verify($reference, $this->wrongCode()), 423, 'OTP_LOCKED');
    }

    #[DataProvider('kinds')]
    public function test_a_new_start_makes_the_previous_reference_unusable(string $kind): void
    {
        $first = $this->reference($kind);
        $second = $this->start($kind === 'real' ? self::ELIGIBLE_ID : self::UNKNOWN_ID)->json('challenge');

        $this->assertNotSame($first, $second);
        $this->assertRefused($this->verify($first), 423, 'OTP_LOCKED');
        $this->assertRefused($this->resend($first), 423, 'OTP_LOCKED');
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
    public function test_the_per_identifier_send_ceiling_stops_resends(string $kind): void
    {
        // Five sends an hour: 3 on the first reference, 2 on the second.
        $first = $this->reference($kind);
        foreach ([61, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->resend($first)->assertOk();
        }
        $second = $this->start($kind === 'real' ? self::ELIGIBLE_ID : self::UNKNOWN_ID)->assertOk()->json('challenge');
        $this->travel(61)->seconds();
        $this->resend($second)->assertOk();

        $this->travel(61)->seconds();
        $this->assertRefused($this->resend($second), 429, 'OTP_SEND_LIMIT');
        $this->assertSame($kind === 'real' ? 5 : 0, $this->sms->attempts);
    }

    #[DataProvider('kinds')]
    public function test_a_malformed_code_is_a_field_error_and_not_an_attempt(string $kind): void
    {
        $reference = $this->reference($kind);

        foreach (['', '12345', '1234567', 'abcdef'] as $code) {
            $this->verify($reference, $code)->assertStatus(422)->assertJsonValidationErrors('code');
        }
        // All five attempts are still available.
        for ($i = 0; $i < 4; $i++) {
            $this->assertRefused($this->verify($reference, $this->wrongCode()), 422, 'OTP_INVALID');
        }
    }

    public function test_an_unknown_or_malformed_reference(): void
    {
        $this->assertRefused($this->verify((string) Str::uuid()), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend((string) Str::uuid()), 422, 'OTP_INVALID');

        $this->verify('not-a-reference')->assertStatus(422)->assertJsonValidationErrors('challenge');
        $this->resend('')->assertStatus(422)->assertJsonValidationErrors('challenge');
    }

    public function test_a_challenge_of_another_purpose_is_not_an_activation_reference(): void
    {
        $head = $this->activatedHead(self::ELIGIBLE_ID);
        $this->trustedMobile($head['person'], self::MOBILE);
        $reset = app(OtpChallenges::class)->issue(OtpPurpose::PASSWORD_RESET, $head['person'], $head['user']);

        $this->assertRefused($this->verify($reset->challenge->uuid, $this->sms->lastCode()), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend($reset->challenge->uuid), 422, 'OTP_INVALID');
        $this->assertNull($reset->challenge->fresh()->verified_at);
    }

    // ------------------------------------------------------------- real only

    public function test_the_correct_code_opens_the_grant_and_activates_nothing(): void
    {
        $reference = $this->reference('real');
        $users = User::count();

        $this->verify($reference, $this->sms->lastCode())->assertOk()
            ->assertExactJson(['verified' => true, 'grant_expires_in_seconds' => 600]);

        $challenge = AuthOtpChallenge::sole();
        $this->assertNotNull($challenge->verified_at);
        $this->assertNull($challenge->consumed_at);
        $this->assertSame($users, User::count());

        // A verified challenge takes no further code and no resend.
        $this->assertRefused($this->verify($reference, $this->sms->lastCode()), 422, 'OTP_INVALID');
        $this->assertRefused($this->resend($reference), 422, 'OTP_INVALID');
    }

    public function test_arabic_digits_in_the_code_are_accepted(): void
    {
        $reference = $this->reference('real');
        $arabic = strtr($this->sms->lastCode(), array_combine(range(0, 9), ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩']));

        $this->verify($reference, $arabic)->assertOk();
    }

    public function test_a_resend_delivers_a_new_code_and_kills_the_old_one(): void
    {
        $reference = $this->reference('real');
        $old = $this->sms->lastCode();

        $this->travel(61)->seconds();
        $this->resend($reference)->assertOk();
        $new = $this->sms->lastCode();
        $this->assertCount(2, $this->sms->sent);

        if ($old !== $new) {
            $this->assertRefused($this->verify($reference, $old), 422, 'OTP_INVALID');
        }
        $this->verify($reference, $new)->assertOk();
    }

    public function test_a_resend_that_fails_at_the_provider_answers_like_a_sent_one(): void
    {
        $reference = $this->reference('real');
        $this->travel(61)->seconds();
        $this->sms->failing = true;

        $this->resend($reference)->assertOk()
            ->assertExactJson(['resend_after_seconds' => 60, 'expires_in_seconds' => 300, 'can_resend' => true]);
    }

    public function test_a_trust_revoked_after_the_start_kills_the_code(): void
    {
        $reference = $this->reference('real');
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        app(RevokePersonMobileTrustAction::class)->handle($admin, PersonMobileTrust::sole(), MobileTrustRevokeReason::REPORTED_LOST);

        $this->assertRefused($this->verify($reference, $this->sms->lastCode()), 423, 'OTP_LOCKED');
        $this->assertRefused($this->resend($reference), 423, 'OTP_LOCKED');
    }

    public function test_a_mobile_changed_after_the_start_kills_the_code(): void
    {
        $reference = $this->reference('real');
        Person::where('national_id', self::ELIGIBLE_ID)->sole()->update(['mobile' => '0599999999']);

        $this->assertRefused($this->verify($reference, $this->sms->lastCode()), 423, 'OTP_LOCKED');
    }
}
