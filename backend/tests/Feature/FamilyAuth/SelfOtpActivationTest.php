<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\GrantPersonMobileTrustAction;
use App\Contracts\SmsSender;
use App\Enums\LifeStatus;
use App\Enums\MobileVerificationMethod;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\CurrentTrustedMobile;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * First self-activation (docs/11 §30a, FP-ADR-053): an eligible household
 * head whose current registered mobile is NOT yet trusted sees it masked
 * (05*****123), confirms it, receives the code on that stored number — and
 * ONLY a correct code makes it the Person's TRUSTED mobile, verification
 * method SELF_OTP. Confirming, failing, expiring or superseding never does.
 * A mobile already TRUSTED is used as it is. Synthetic data only.
 */
class SelfOtpActivationTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const BASE = '/api/v1/family/auth/activation';

    private const NATIONAL_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    private const MOBILE = '0591234567';

    private const MASKED = '05*****567';

    private const PASSWORD = 'synthetic-pass-1';

    private FakeSmsSender $sms;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['family_auth.activation_enabled' => true, 'family_auth.activation.min_response_ms' => 0]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        [$this->person] = $this->eligibleHead(self::NATIONAL_ID);
        // Registered, never verified: no trust row at all.
        $this->person->forceFill(['mobile' => self::MOBILE])->saveQuietly();
        $this->freezeSecond();
    }

    private function start(string $nationalId = self::NATIONAL_ID, array $extra = []): TestResponse
    {
        return $this->postJson(self::BASE.'/start', ['national_id' => $nationalId, ...$extra]);
    }

    private function send(string $confirmation, array $extra = []): TestResponse
    {
        return $this->postJson(self::BASE.'/send', ['confirmation' => $confirmation, ...$extra]);
    }

    private function verify(string $challenge, string $code): TestResponse
    {
        return $this->postJson(self::BASE.'/verify', ['challenge' => $challenge, 'code' => $code]);
    }

    private function complete(string $challenge): TestResponse
    {
        return $this->postJson(self::BASE.'/complete', ['challenge' => $challenge, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], ['Referer' => 'http://localhost:3000']);
    }

    private function wrong(): string
    {
        return $this->sms->lastCode() === '000000' ? '111111' : '000000';
    }

    private function trustedCount(): int
    {
        return PersonMobileTrust::where('person_id', $this->person->id)->where('status', 'TRUSTED')->count();
    }

    // ---------------------------------------------------------- confirmation

    public function test_an_eligible_untrusted_head_gets_a_confirmation_with_the_masked_number_only(): void
    {
        $response = $this->start()->assertOk();

        $response->assertExactJson(['confirmation' => $response->json('confirmation'), 'masked_mobile' => self::MASKED]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $response->json('confirmation'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach ([self::MOBILE, '1234567', '91234567'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
        // Nothing sent, nothing trusted, no challenge, no pending row yet.
        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, PersonMobileTrust::count());
        $this->assertSame(0, AuthOtpChallenge::count());
    }

    public function test_the_client_can_never_name_or_replace_a_number(): void
    {
        $this->start(extra: ['mobile' => '0599999999'])->assertStatus(422)->assertJsonValidationErrors('mobile');
        $this->start(extra: ['phone' => '0599999999'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $confirmation = $this->start()->assertOk()->json('confirmation');
        $this->send($confirmation, ['mobile' => '0599999999'])->assertStatus(422)->assertJsonValidationErrors('mobile');

        $this->send($confirmation)->assertOk();
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);
    }

    public function test_confirming_sends_the_code_to_the_stored_number_and_creates_no_trust(): void
    {
        $confirmation = $this->start()->json('confirmation');

        $response = $this->send($confirmation)->assertOk();

        $response->assertExactJson([
            'challenge' => $response->json('challenge'),
            'resend_after_seconds' => 60,
            'expires_in_seconds' => 300,
            'can_resend' => true,
        ]);
        $this->assertSame(1, $this->sms->attempts);
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);
        $this->assertSame(0, $this->trustedCount(), 'A click is not verification.');
        $pending = PersonMobileTrust::sole();
        $this->assertSame('PENDING_VERIFICATION', $pending->status->value);
        $this->assertNull($pending->verification_method);
        $this->assertSame($pending->id, AuthOtpChallenge::sole()->mobile_trust_id);
        $this->assertFalse(app(CurrentTrustedMobile::class)->for($this->person->fresh())->isTrusted());
    }

    public function test_a_confirmation_is_used_once_real_or_decoy(): void
    {
        foreach ([self::NATIONAL_ID, self::UNKNOWN_ID] as $id) {
            $confirmation = $this->start($id)->json('confirmation');
            $this->send($confirmation)->assertOk();
            $this->send($confirmation)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        }
    }

    // ------------------------------------------------- only a correct code

    public function test_a_wrong_code_creates_no_trust(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($challenge, $this->wrong());
        }

        $this->assertSame(0, $this->trustedCount());
        $this->assertSame('PENDING_VERIFICATION', PersonMobileTrust::sole()->status->value);
    }

    public function test_an_expired_code_creates_no_trust(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $code = $this->sms->lastCode();
        $this->travel(301)->seconds();

        $this->verify($challenge, $code)->assertJsonPath('code', 'OTP_EXPIRED');

        $this->assertSame(0, $this->trustedCount());
    }

    public function test_a_superseded_code_creates_no_trust(): void
    {
        $old = $this->startActivationChallenge(self::NATIONAL_ID);
        $oldCode = $this->sms->lastCode();
        $this->startActivationChallenge(self::NATIONAL_ID);

        $this->verify($old, $oldCode)->assertJsonPath('code', 'OTP_LOCKED');

        $this->assertSame(0, $this->trustedCount());
        // The new challenge reuses the same pending row for the same number.
        $this->assertSame(1, PersonMobileTrust::count());
    }

    public function test_a_correct_code_makes_the_number_trusted_by_self_otp(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);

        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        $trust = PersonMobileTrust::sole();
        $this->assertSame('TRUSTED', $trust->status->value);
        $this->assertSame(MobileVerificationMethod::SELF_OTP, $trust->verification_method);
        $this->assertNull($trust->verified_by, 'Self-verified: no Staff verifier.');
        $this->assertNotNull($trust->verified_at);
        $this->assertSame('67', $trust->mobile_last2);
        $current = app(CurrentTrustedMobile::class)->for($this->person->fresh());
        $this->assertTrue($current->isTrusted());
        $this->assertTrue($current->trust->is($trust));
        $this->assertSame($trust->id, AuthOtpChallenge::sole()->mobile_trust_id);
    }

    public function test_the_self_verified_trust_is_audited(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        $event = AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_GRANTED')->sole();
        $this->assertSame(['SUCCESS', 'SELF_OTP'], [$event->outcome->value, $event->reason_code]);
        $this->assertSame(['verification_method' => 'SELF_OTP'], $event->metadata);
        $this->assertSame($this->person->id, $event->person_id);
        $this->assertNull($event->actor_user_id);
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'OTP_VERIFIED')->count());
        $this->assertStringNotContainsString(self::MOBILE, AuthSecurityEvent::all()->toJson());
    }

    public function test_the_whole_activation_completes_on_a_self_verified_number(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        $this->complete($challenge)->assertCreated();

        $this->assertSame(1, User::whereHas('personLinks', fn ($q) => $q->where('person_id', $this->person->id))->count());
        $this->assertSame(MobileVerificationMethod::SELF_OTP, PersonMobileTrust::sole()->verification_method);
        $this->assertAuthenticated('web');
    }

    public function test_a_second_correct_verify_never_creates_a_second_trust(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $code = $this->sms->lastCode();

        $this->verify($challenge, $code)->assertOk();
        $this->verify($challenge, $code)->assertStatus(422);

        $this->assertSame(1, $this->trustedCount());
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_GRANTED')->count());
    }

    // ------------------------------------------------- existing trust kept

    public function test_an_existing_staff_trust_is_used_as_it_is(): void
    {
        $existing = $this->trustedMobile($this->person, self::MOBILE);
        $confirmation = $this->start()->assertJsonPath('masked_mobile', self::MASKED)->json('confirmation');
        $challenge = $this->send($confirmation)->json('challenge');

        $this->assertSame($existing->id, AuthOtpChallenge::sole()->mobile_trust_id);
        $this->verify($challenge, $this->sms->lastCode())->assertOk();
        $this->complete($challenge)->assertCreated();

        // Never replaced, never duplicated, never re-labelled.
        $this->assertSame(1, PersonMobileTrust::count());
        $this->assertSame(MobileVerificationMethod::IN_PERSON, $existing->fresh()->verification_method);
        $this->assertSame('TRUSTED', $existing->fresh()->status->value);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_GRANTED')->count());
    }

    public function test_a_staff_grant_made_while_the_code_is_pending_is_kept_not_duplicated(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $admin = User::factory()->create()->assignRole('ADMINISTRATOR');
        $staff = app(GrantPersonMobileTrustAction::class)->handle($admin, $this->person, MobileVerificationMethod::STAFF_CALLBACK);

        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        $this->assertSame(1, $this->trustedCount());
        $this->assertSame(MobileVerificationMethod::STAFF_CALLBACK, $staff->fresh()->verification_method);
        $this->assertSame($staff->id, AuthOtpChallenge::sole()->mobile_trust_id);
        $this->complete($challenge)->assertCreated();
    }

    public function test_a_number_changed_after_the_code_was_sent_proves_nothing(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $code = $this->sms->lastCode();
        $this->person->forceFill(['mobile' => '0597777777'])->saveQuietly();

        $this->verify($challenge, $code)->assertJsonPath('code', 'OTP_LOCKED');

        $this->assertSame(0, $this->trustedCount());
    }

    public function test_a_number_changed_after_the_confirmation_is_never_sent_to(): void
    {
        $confirmation = $this->start()->json('confirmation');
        $this->person->forceFill(['mobile' => '0597777777'])->saveQuietly();

        $this->send($confirmation)->assertOk();

        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, AuthOtpChallenge::count());
    }

    public function test_a_later_number_change_makes_the_self_verified_trust_stale(): void
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $this->verify($challenge, $this->sms->lastCode())->assertOk();
        $trust = PersonMobileTrust::sole();

        $this->person->fresh()->forceFill(['mobile' => '0597777777'])->save();

        $this->assertSame('STALE', $trust->fresh()->status->value);
    }

    // ------------------------------------------------------ denied = decoy

    /** @return array<string, array{0: string}> */
    public static function denials(): array
    {
        return [
            'no valid mobile' => ['no-mobile'],
            'invalid mobile' => ['bad-mobile'],
            'deceased' => ['deceased'],
            'unknown life status' => ['life-unknown'],
            'inactive person' => ['inactive'],
            'not the head' => ['non-head'],
            'unknown national id' => ['unknown'],
            // Staff revoked the trust: self-verification never undoes that.
            'revoked by staff' => ['revoked'],
        ];
    }

    #[DataProvider('denials')]
    public function test_a_denied_identifier_answers_alike_and_nothing_is_sent_or_trusted(string $case): void
    {
        $eligible = $this->start()->assertOk()->json();
        AuthSecurityEvent::query()->delete();
        $id = self::NATIONAL_ID;
        match ($case) {
            'no-mobile' => $this->person->forceFill(['mobile' => null])->saveQuietly(),
            'bad-mobile' => $this->person->forceFill(['mobile' => '12345'])->saveQuietly(),
            'deceased' => $this->person->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->saveQuietly(),
            'life-unknown' => $this->person->forceFill(['life_status' => LifeStatus::UNKNOWN])->saveQuietly(),
            'inactive' => $this->person->forceFill(['is_active' => false])->saveQuietly(),
            'non-head' => $this->person->activeMembership()->first()->forceFill(['is_household_head' => false])->save(),
            'unknown' => $id = self::UNKNOWN_ID,
            'revoked' => $this->trustedMobile($this->person, self::MOBILE)->forceFill([
                'status' => 'REVOKED', 'revoked_by' => User::factory()->create()->id, 'revoked_at' => now(), 'revoke_reason' => 'REPORTED_COMPROMISE',
            ])->save(),
        };
        $trustsBefore = PersonMobileTrust::count();

        $denied = $this->start($id)->assertOk();

        $this->assertSame(array_keys($eligible), array_keys($denied->json()));
        $this->assertMatchesRegularExpression('/\A05\*{5}[0-9]{3}\z/', $denied->json('masked_mobile'));
        // A stable mask: repeating the start reveals nothing.
        $this->assertSame($denied->json('masked_mobile'), $this->start($id)->json('masked_mobile'));
        $challenge = $this->send($denied->json('confirmation'))->assertOk();
        $this->assertSame(['challenge', 'resend_after_seconds', 'expires_in_seconds', 'can_resend'], array_keys($challenge->json()));
        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame($trustsBefore, PersonMobileTrust::count(), 'No pending or trusted row is created.');
        $this->assertSame(0, $this->trustedCount());
        $this->assertGreaterThan(0, AuthSecurityEvent::where('outcome', 'DENIED')->count());
    }

    // ------------------------------------------------- other flows unchanged

    public function test_password_reset_still_refuses_an_untrusted_number(): void
    {
        config(['family_auth.password_reset_enabled' => true]);
        $account = $this->activatedHead('223456789');
        $account['person']->forceFill(['mobile' => '0597654321'])->saveQuietly();

        $this->postJson('/api/v1/family/auth/password/reset/start', ['national_id' => '223456789'])->assertOk();

        $this->assertSame(0, $this->sms->attempts);
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, PersonMobileTrust::count(), 'A reset never creates a pending or trusted row.');
    }

    public function test_a_staff_grant_cannot_claim_self_otp(): void
    {
        $admin = User::factory()->create()->assignRole('ADMINISTRATOR');

        $this->actingAs($admin)->postJson('/api/v1/people/'.$this->person->person_code.'/mobile-trust', ['verification_method' => 'SELF_OTP'])
            ->assertStatus(422)->assertJsonValidationErrors('verification_method');
        $this->assertSame(0, PersonMobileTrust::count());

        $this->expectException(\InvalidArgumentException::class);
        app(GrantPersonMobileTrustAction::class)->handle($admin, $this->person, MobileVerificationMethod::SELF_OTP);
    }
}
