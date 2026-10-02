<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\UpdatePersonAction;
use App\Contracts\SmsSender;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\FingerprintContext;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\FamilyAuth\MobileTrusts;
use App\Support\FamilyAuth\OtpChallenges;
use App\Support\FamilyAuth\OtpResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1E: the OTP challenge service (docs/11 §30a) — issue, resend, verify,
 * consume, supersede. The code is read from the fake SMS sender only: never
 * from the database, a log or a security event. Synthetic data only.
 */
class OtpChallengesTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const MOBILE = '0591234567';

    private OtpChallenges $otp;

    private FakeSmsSender $sms;

    private Person $person;

    private PersonMobileTrust $trust;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        Cache::flush();
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->otp = app(OtpChallenges::class);
        $this->person = Person::factory()->create();
        $this->trust = $this->trustedMobile($this->person, self::MOBILE);
        $this->freezeSecond();
    }

    private function issue(?Person $person = null, OtpPurpose $purpose = OtpPurpose::ACTIVATION, ?User $user = null): OtpResult
    {
        return $this->otp->issue($purpose, $person ?? $this->person, $user);
    }

    private function verify(AuthOtpChallenge $challenge, string $code, OtpPurpose $purpose = OtpPurpose::ACTIVATION, ?Person $person = null): OtpResult
    {
        return $this->otp->verify($challenge->uuid, $purpose, $code, $person);
    }

    private function consume(AuthOtpChallenge $challenge, ?Person $person = null, OtpPurpose $purpose = OtpPurpose::ACTIVATION, ?User $user = null): OtpResult
    {
        return DB::transaction(fn () => $this->otp->consume($challenge->uuid, $purpose, $person ?? $this->person, $user));
    }

    /** A code that is certainly not the right one. */
    private function wrong(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }

    private function assertFailed(OtpFailure $expected, OtpResult $result): void
    {
        $this->assertFalse($result->succeeded());
        $this->assertSame($expected, $result->failure);
    }

    /** @return list<string> */
    private function events(): array
    {
        return AuthSecurityEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all();
    }

    // ------------------------------------------------------------------ issue

    public function test_issue_sends_a_six_digit_code_to_the_trusted_mobile(): void
    {
        $result = $this->issue();

        $this->assertTrue($result->succeeded());
        $code = $this->sms->lastCode();
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);
        $this->assertSame('ACTIVATION', $this->sms->last()->purpose);

        $challenge = $result->challenge->fresh();
        $this->assertSame(OtpPurpose::ACTIVATION, $challenge->purpose);
        $this->assertSame($this->person->id, $challenge->person_id);
        $this->assertSame($this->trust->id, $challenge->mobile_trust_id);
        $this->assertNull($challenge->user_id);
        $this->assertSame(0, $challenge->attempts);
        $this->assertSame(1, $challenge->send_count);
        $this->assertEquals(now()->addSeconds(300), $challenge->expires_at);
        $this->assertNull($challenge->verified_at);
        $this->assertSame(['OTP_ISSUED'], $this->events());
        $this->assertSame(AuthSecurityEventOutcome::SUCCESS, AuthSecurityEvent::sole()->outcome);
    }

    public function test_codes_come_from_the_whole_six_digit_space_with_leading_zeros(): void
    {
        $codes = [];
        for ($i = 0; $i < 40; $i++) {
            Cache::flush();
            $this->issue();
            $codes[] = $this->sms->lastCode();
        }

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        }
        // Not a fixed or sequential value.
        $this->assertGreaterThan(35, count(array_unique($codes)));
    }

    public function test_the_plaintext_code_is_never_stored_logged_or_audited(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();
        $this->verify($challenge, $this->wrong($code));
        $this->verify($challenge, $code);
        $this->consume($challenge);

        // The challenge row: a keyed hash bound to this challenge only.
        $row = (array) DB::table('auth_otp_challenges')->where('id', $challenge->id)->first();
        $this->assertStringNotContainsString($code, json_encode($row));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row['code_hash']);
        $this->assertNotSame(hash('sha256', $code), $row['code_hash']);
        $this->assertArrayNotHasKey('code_hash', $challenge->fresh()->toArray());

        // The security events.
        $this->assertGreaterThan(3, AuthSecurityEvent::count());
        foreach (DB::table('auth_security_events')->get() as $event) {
            $json = json_encode($event);
            $this->assertStringNotContainsString($code, $json);
            $this->assertStringNotContainsString(self::MOBILE, $json);
        }

        // The ordinary application log.
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($code, $line);
        }

        // And no method hands the code back.
        $return = (new ReflectionClass(OtpResult::class))->getProperties();
        $this->assertSame(['failure', 'challenge'], array_map(fn ($p) => $p->getName(), $return));
    }

    public function test_the_hash_is_bound_to_its_challenge(): void
    {
        $first = $this->issue()->challenge;
        $code = $this->sms->lastCode();
        $other = Person::factory()->create();
        $this->trustedMobile($other, '0567654321');
        $second = $this->issue($other)->challenge;

        // The same digits under another challenge give another hash.
        $this->assertNotSame(
            KeyedFingerprint::of(FingerprintContext::OTP_CODE, $first->uuid.':'.$code),
            KeyedFingerprint::of(FingerprintContext::OTP_CODE, $second->uuid.':'.$code),
        );
        $this->assertSame(
            KeyedFingerprint::of(FingerprintContext::OTP_CODE, $first->uuid.':'.$code),
            DB::table('auth_otp_challenges')->where('id', $first->id)->value('code_hash'),
        );
    }

    public function test_issue_requires_the_current_trusted_mobile(): void
    {
        $unverified = Person::factory()->create(['mobile' => '0567654321']);
        $noMobile = Person::factory()->create(['mobile' => null]);

        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->issue($unverified));
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->issue($noMobile));

        // A number changed behind the model's back: the trust no longer matches.
        DB::table('persons')->where('id', $this->person->id)->update(['mobile' => '0567654321']);
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->issue($this->person->fresh()));

        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, $this->sms->attempts);
    }

    public function test_a_new_issue_supersedes_the_previous_open_challenge(): void
    {
        $first = $this->issue()->challenge;
        $firstCode = $this->sms->lastCode();

        $second = $this->issue()->challenge;

        // Only one open challenge per Person and purpose.
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(1, AuthOtpChallenge::query()->open()->where('person_id', $this->person->id)->count());
        // The superseded one is unusable immediately, even with its right code.
        $this->assertFailed(OtpFailure::SUPERSEDED, $this->verify($first, $firstCode));
        $this->assertTrue($this->verify($second, $this->sms->lastCode())->succeeded());
    }

    public function test_an_expired_open_challenge_is_superseded_by_the_next_issue(): void
    {
        $first = $this->issue()->challenge;
        $this->travel(301)->seconds();

        // The partial unique index cannot know about expiry: the service does.
        $second = $this->issue();

        $this->assertTrue($second->succeeded());
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(2, AuthOtpChallenge::count());
    }

    public function test_another_purpose_is_an_independent_challenge(): void
    {
        $user = $this->familyUser();
        $activation = $this->issue()->challenge;
        $reset = $this->issue(null, OtpPurpose::PASSWORD_RESET, $user)->challenge;

        $this->assertNull($activation->fresh()->superseded_at);
        $this->assertSame($user->id, $reset->fresh()->user_id);
        $this->assertSame(2, AuthOtpChallenge::query()->open()->count());
    }

    public function test_a_password_reset_challenge_needs_a_user(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issue(null, OtpPurpose::PASSWORD_RESET);
    }

    // ----------------------------------------------------------------- verify

    public function test_the_correct_code_verifies_and_opens_a_ten_minute_grant(): void
    {
        $challenge = $this->issue()->challenge;

        $result = $this->verify($challenge, $this->sms->lastCode());

        $this->assertTrue($result->succeeded());
        $fresh = $challenge->fresh();
        $this->assertEquals(now(), $fresh->verified_at);
        $this->assertEquals(now()->addSeconds(600), $fresh->grant_expires_at);
        // Verified is not consumed.
        $this->assertNull($fresh->consumed_at);
        $this->assertSame(0, $fresh->attempts);
        $this->assertSame(['OTP_ISSUED', 'OTP_VERIFIED'], $this->events());
    }

    public function test_a_wrong_code_counts_an_attempt_and_the_fifth_locks(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($challenge, $this->wrong($code)));
            $this->assertSame($attempt, $challenge->fresh()->attempts);
            $this->assertNull($challenge->fresh()->locked_at);
        }
        $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($challenge, $this->wrong($code)));
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertNotNull($challenge->fresh()->locked_at);

        // Locked: even the correct code fails, and no more attempts are counted.
        $this->assertFailed(OtpFailure::LOCKED, $this->verify($challenge, $code));
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertNull($challenge->fresh()->verified_at);

        $this->assertSame(
            ['OTP_ISSUED', 'OTP_FAILED', 'OTP_FAILED', 'OTP_FAILED', 'OTP_FAILED', 'OTP_FAILED', 'OTP_LOCKED'],
            $this->events(),
        );
    }

    public function test_malformed_codes_are_wrong_attempts(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        foreach (['', '12345', $code.'0', ' '.$code, 'abcdef'] as $i => $input) {
            if ($i === 4) {
                break;
            }
            $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($challenge, $input));
        }
        $this->assertSame(4, $challenge->fresh()->attempts);
        $this->assertTrue($this->verify($challenge, $code)->succeeded());
    }

    public function test_a_code_expires_at_exactly_five_minutes(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        $this->travel(299)->seconds();
        $other = $this->issue(tap(Person::factory()->create(), fn (Person $p) => $this->trustedMobile($p, '0567654321')))->challenge;
        $otherCode = $this->sms->lastCode();
        $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($challenge, $this->wrong($code)));

        $this->travel(1)->seconds();
        // At 300 seconds the first challenge is expired; an expired check
        // counts no attempt.
        $this->assertFailed(OtpFailure::EXPIRED, $this->verify($challenge, $code));
        $this->assertSame(1, $challenge->fresh()->attempts);
        $this->assertNull($challenge->fresh()->verified_at);
        // The other one (issued 299 seconds later) is still valid.
        $this->assertTrue($this->verify($other, $otherCode)->succeeded());
    }

    public function test_a_verified_challenge_does_not_accept_a_code_again(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();
        $this->verify($challenge, $code);

        $this->assertFailed(OtpFailure::ALREADY_VERIFIED, $this->verify($challenge, $code));
        $this->assertFailed(OtpFailure::ALREADY_VERIFIED, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
    }

    public function test_purpose_person_and_unknown_challenges_are_refused(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();
        $stranger = Person::factory()->create();

        $this->assertFailed(OtpFailure::PURPOSE_MISMATCH, $this->verify($challenge, $code, OtpPurpose::PASSWORD_RESET));
        $this->assertFailed(OtpFailure::PERSON_MISMATCH, $this->verify($challenge, $code, OtpPurpose::ACTIVATION, $stranger));
        $this->assertFailed(OtpFailure::NOT_FOUND, $this->otp->verify((string) Str::uuid(), OtpPurpose::ACTIVATION, $code));
        $this->assertFailed(OtpFailure::NOT_FOUND, $this->otp->verify('not-a-uuid', OtpPurpose::ACTIVATION, $code));

        // None of those cost an attempt, and the right call still works.
        $this->assertSame(0, $challenge->fresh()->attempts);
        $this->assertTrue($this->verify($challenge, $code, OtpPurpose::ACTIVATION, $this->person)->succeeded());
    }

    public function test_a_code_for_one_person_never_works_for_another_on_a_shared_mobile(): void
    {
        $other = Person::factory()->create();
        $this->trustedMobile($other, self::MOBILE);

        $mine = $this->issue()->challenge;
        $myCode = $this->sms->lastCode();
        $theirs = $this->issue($other)->challenge;
        $theirCode = $this->sms->lastCode();

        // Same phone, two independent challenges.
        $this->assertNull($mine->fresh()->superseded_at);
        $this->assertNotSame($mine->mobile_trust_id, $theirs->mobile_trust_id);
        if ($myCode !== $theirCode) {
            $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($theirs, $myCode));
        }
        $this->assertTrue($this->verify($mine, $myCode)->succeeded());
        // A's grant cannot be consumed for B.
        $this->assertFailed(OtpFailure::PERSON_MISMATCH, $this->consume($mine, $other));
        $this->assertTrue($this->consume($mine)->succeeded());
    }

    public function test_a_failed_attempt_cannot_be_rolled_back_by_a_caller(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        foreach ([
            fn () => $this->otp->verify($challenge->uuid, OtpPurpose::ACTIVATION, $this->wrong($code)),
            fn () => $this->otp->issue(OtpPurpose::ACTIVATION, $this->person),
            fn () => $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('An OTP operation ran inside a caller transaction.');
            } catch (LogicException) {
            }
        }
        $this->assertSame(0, $challenge->fresh()->attempts);
        $this->assertSame(1, $this->sms->attempts);

        // Outside a caller transaction the attempt is counted and stays.
        $this->verify($challenge, $this->wrong($code));
        $this->assertSame(1, $challenge->fresh()->attempts);
    }

    // ----------------------------------------------------------------- resend

    public function test_resend_respects_the_sixty_second_cooldown(): void
    {
        $challenge = $this->issue()->challenge;

        $this->travel(59)->seconds();
        $this->assertFailed(OtpFailure::COOLDOWN, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(1, $challenge->fresh()->send_count);
        $this->assertSame(1, $this->sms->attempts);

        $this->travel(1)->seconds();
        $this->assertTrue($this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION)->succeeded());
        $this->assertSame(2, $challenge->fresh()->send_count);
        $this->assertSame(2, $this->sms->attempts);
    }

    public function test_resend_issues_a_new_code_on_the_same_row_and_kills_the_old_one(): void
    {
        $challenge = $this->issue()->challenge;
        $oldCode = $this->sms->lastCode();
        $oldHash = DB::table('auth_otp_challenges')->where('id', $challenge->id)->value('code_hash');
        $this->verify($challenge, $this->wrong($oldCode));
        $this->verify($challenge, $this->wrong($oldCode));

        $this->travel(60)->seconds();
        $result = $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION, $this->person);
        $newCode = $this->sms->lastCode();

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->challenge->is($challenge));
        $this->assertSame(1, AuthOtpChallenge::count());
        $fresh = $challenge->fresh();
        $this->assertNotSame($oldHash, DB::table('auth_otp_challenges')->where('id', $challenge->id)->value('code_hash'));
        $this->assertEquals(now(), $fresh->last_sent_at);
        // The five-minute expiry restarts from the resend…
        $this->assertEquals(now()->addSeconds(300), $fresh->expires_at);
        // …but the attempts already used are NOT given back.
        $this->assertSame(2, $fresh->attempts);

        if ($oldCode !== $newCode) {
            $this->assertFailed(OtpFailure::CODE_MISMATCH, $this->verify($challenge, $oldCode));
            $this->assertSame(3, $challenge->fresh()->attempts);
        }
        $this->assertTrue($this->verify($challenge, $newCode)->succeeded());
        $this->assertSame('OTP_ISSUED', $this->events()[3]);
        $this->assertSame(2, AuthSecurityEvent::where('event_type', 'OTP_ISSUED')->orderByDesc('id')->first()->metadata['send_count']);
    }

    public function test_three_sends_never_give_more_than_five_attempts(): void
    {
        $challenge = $this->issue()->challenge;
        $wrong = fn () => $this->verify($challenge, $this->wrong($this->sms->lastCode()));

        $wrong();
        $wrong();
        $this->travel(60)->seconds();
        $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION);
        $wrong();
        $wrong();
        $this->travel(60)->seconds();
        $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION);
        $wrong();

        // Five attempts in total across three sends: locked.
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertSame(3, $challenge->fresh()->send_count);
        $this->assertNotNull($challenge->fresh()->locked_at);
        $this->assertFailed(OtpFailure::LOCKED, $this->verify($challenge, $this->sms->lastCode()));
    }

    public function test_the_third_send_is_allowed_and_the_fourth_is_refused(): void
    {
        $challenge = $this->issue()->challenge;

        $this->travel(60)->seconds();
        $this->assertTrue($this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION)->succeeded());
        $this->travel(60)->seconds();
        $this->assertTrue($this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION)->succeeded());
        $this->assertSame(3, $challenge->fresh()->send_count);

        $this->travel(60)->seconds();
        $this->assertFailed(OtpFailure::SEND_LIMIT, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(3, $challenge->fresh()->send_count);
        $this->assertSame(3, $this->sms->attempts);
        // The third code still works.
        $this->assertTrue($this->verify($challenge, $this->sms->lastCode())->succeeded());
    }

    public function test_an_expired_challenge_is_not_resurrected_by_a_resend(): void
    {
        $challenge = $this->issue()->challenge;
        $this->travel(300)->seconds();

        $this->assertFailed(OtpFailure::EXPIRED, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(1, $challenge->fresh()->send_count);
        $this->assertSame(1, $this->sms->attempts);

        // The caller starts a fresh challenge instead.
        $fresh = $this->issue();
        $this->assertTrue($fresh->succeeded());
        $this->assertFalse($fresh->challenge->is($challenge));
        $this->assertNotNull($challenge->fresh()->superseded_at);
    }

    public function test_resend_refuses_finished_or_foreign_challenges(): void
    {
        $challenge = $this->issue()->challenge;
        $this->travel(60)->seconds();

        $this->assertFailed(OtpFailure::PURPOSE_MISMATCH, $this->otp->resend($challenge->uuid, OtpPurpose::PASSWORD_RESET));
        $this->assertFailed(OtpFailure::PERSON_MISMATCH, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION, Person::factory()->create()));
        $this->assertFailed(OtpFailure::NOT_FOUND, $this->otp->resend('nope', OtpPurpose::ACTIVATION));

        $this->otp->supersede($this->person, OtpPurpose::ACTIVATION);
        $this->assertFailed(OtpFailure::SUPERSEDED, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(1, $this->sms->attempts);
    }

    // ---------------------------------------------------------------- consume

    public function test_consume_uses_the_grant_once(): void
    {
        $challenge = $this->issue()->challenge;
        $this->verify($challenge, $this->sms->lastCode());

        $this->assertTrue($this->consume($challenge)->succeeded());
        $this->assertEquals(now(), $challenge->fresh()->consumed_at);

        // Single use: not again, and not for a new code either.
        $this->assertFailed(OtpFailure::CONSUMED, $this->consume($challenge));
        $this->assertFailed(OtpFailure::CONSUMED, $this->verify($challenge, $this->sms->lastCode()));
        $this->assertFailed(OtpFailure::CONSUMED, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(['OTP_ISSUED', 'OTP_VERIFIED', 'OTP_CONSUMED'], $this->events());
    }

    public function test_consume_needs_a_verified_challenge_within_ten_minutes(): void
    {
        $unverified = $this->issue()->challenge;
        $this->assertFailed(OtpFailure::NOT_VERIFIED, $this->consume($unverified));

        $this->verify($unverified, $this->sms->lastCode());
        // The grant outlives the code's own five-minute expiry…
        $this->travel(599)->seconds();
        $other = tap(Person::factory()->create(), fn (Person $p) => $this->trustedMobile($p, '0567654321'));
        $late = $this->issue($other)->challenge;
        $this->verify($late, $this->sms->lastCode());
        $this->assertTrue($this->consume($unverified)->succeeded());

        // …and ends at exactly 600 seconds.
        $this->travel(600)->seconds();
        $this->assertFailed(OtpFailure::GRANT_EXPIRED, $this->consume($late, $other));
        $this->assertNull($late->fresh()->consumed_at);
    }

    public function test_consume_checks_purpose_person_and_user(): void
    {
        $user = $this->familyUser();
        $reset = $this->issue(null, OtpPurpose::PASSWORD_RESET, $user)->challenge;
        $this->verify($reset, $this->sms->lastCode(), OtpPurpose::PASSWORD_RESET);

        $this->assertFailed(OtpFailure::PURPOSE_MISMATCH, $this->consume($reset, null, OtpPurpose::ACTIVATION, $user));
        $this->assertFailed(OtpFailure::PERSON_MISMATCH, $this->consume($reset, Person::factory()->create(), OtpPurpose::PASSWORD_RESET, $user));
        // The reset belongs to one account.
        $this->assertFailed(OtpFailure::USER_MISMATCH, $this->consume($reset, null, OtpPurpose::PASSWORD_RESET));
        $this->assertFailed(OtpFailure::USER_MISMATCH, $this->consume($reset, null, OtpPurpose::PASSWORD_RESET, $this->familyUser()));
        $this->assertNull($reset->fresh()->consumed_at);

        $this->assertTrue($this->consume($reset, null, OtpPurpose::PASSWORD_RESET, $user)->succeeded());

        // An activation challenge has no user: passing one is a mismatch.
        $activation = $this->issue()->challenge;
        $this->verify($activation, $this->sms->lastCode());
        $this->assertFailed(OtpFailure::USER_MISMATCH, $this->consume($activation, null, OtpPurpose::ACTIVATION, $user));
    }

    public function test_consume_only_runs_inside_the_callers_transaction(): void
    {
        $challenge = $this->issue()->challenge;
        $this->verify($challenge, $this->sms->lastCode());

        try {
            $this->otp->consume($challenge->uuid, OtpPurpose::ACTIVATION, $this->person);
            $this->fail('A grant was consumed outside a workflow transaction.');
        } catch (LogicException) {
        }

        // The consumption follows the caller: a failed workflow leaves the
        // grant usable.
        try {
            DB::transaction(function () use ($challenge) {
                $this->assertTrue($this->otp->consume($challenge->uuid, OtpPurpose::ACTIVATION, $this->person)->succeeded());
                throw new \RuntimeException('the activation failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull($challenge->fresh()->consumed_at);
        $this->assertNotContains('OTP_CONSUMED', $this->events());
        $this->assertTrue($this->consume($challenge)->succeeded());
    }

    // ------------------------------------------------- trust bound to a number

    public function test_changing_the_mobile_after_issue_invalidates_the_challenge(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        app(UpdatePersonAction::class)->handle($this->person->fresh(), ['mobile' => '0567654321'], null);

        // The old code proves nothing about the new number.
        $this->assertNotNull($challenge->fresh()->superseded_at);
        $this->assertFailed(OtpFailure::SUPERSEDED, $this->verify($challenge, $code));
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->issue($this->person->fresh()));
    }

    public function test_a_number_changed_behind_the_model_is_still_refused(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();
        DB::table('persons')->where('id', $this->person->id)->update(['mobile' => '0567654321']);

        // No hook ran, so the challenge is still open — and still unusable.
        $this->assertNull($challenge->fresh()->superseded_at);
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->verify($challenge, $code));
        $this->assertSame(0, $challenge->fresh()->attempts);
        $this->travel(60)->seconds();
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
    }

    public function test_a_verified_grant_dies_with_its_trust(): void
    {
        $challenge = $this->issue()->challenge;
        $this->verify($challenge, $this->sms->lastCode());
        DB::table('persons')->where('id', $this->person->id)->update(['mobile' => '0567654321']);

        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->consume($challenge));
        $this->assertNull($challenge->fresh()->consumed_at);
    }

    public function test_revoking_or_staling_the_trust_supersedes_its_open_challenges(): void
    {
        $challenge = $this->issue()->challenge;
        $code = $this->sms->lastCode();

        $this->trust->forceFill([
            'status' => 'REVOKED', 'revoked_by' => User::factory()->create()->id, 'revoked_at' => now(),
            'revoke_reason' => MobileTrustRevokeReason::REPORTED_LOST->value,
        ])->save();
        AuthOtpChallenge::supersedeOpenForTrust($this->trust->id);
        $this->assertFailed(OtpFailure::SUPERSEDED, $this->verify($challenge, $code));

        // The same through the stale transition.
        $other = Person::factory()->create();
        $this->trustedMobile($other, '0567654321');
        $second = $this->issue($other)->challenge;
        MobileTrusts::markStale($other);
        $this->assertNotNull($second->fresh()->superseded_at);
        $this->assertFailed(OtpFailure::TRUST_NOT_CURRENT, $this->issue($other));
    }

    // --------------------------------------------------- delivery and throttle

    public function test_a_delivery_failure_leaves_a_consistent_counted_challenge(): void
    {
        $this->sms->failing = true;

        $result = $this->issue();

        $this->assertFailed(OtpFailure::DELIVERY_FAILED, $result);
        // The challenge exists and the send attempt counted.
        $challenge = $result->challenge->fresh();
        $this->assertSame(1, $challenge->send_count);
        $this->assertSame(1, $this->sms->attempts);
        $this->assertCount(0, $this->sms->sent);
        $event = AuthSecurityEvent::sole();
        $this->assertSame('OTP_ISSUED', $event->event_type->value);
        $this->assertSame(AuthSecurityEventOutcome::FAILURE, $event->outcome);
        $this->assertSame('DELIVERY_FAILED', $event->reason_code);

        // No automatic retry; the next send waits for the cooldown.
        $this->assertFailed(OtpFailure::COOLDOWN, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(1, $this->sms->attempts);
        $this->sms->failing = false;
        $this->travel(60)->seconds();
        $this->assertTrue($this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION)->succeeded());
        $this->assertSame(2, $challenge->fresh()->send_count);
    }

    public function test_failed_deliveries_count_toward_the_send_limit_and_the_throttle(): void
    {
        config(['family_auth.throttle.person.hour' => 3]);
        $this->sms->failing = true;

        $this->assertFailed(OtpFailure::DELIVERY_FAILED, $this->issue());
        $this->assertFailed(OtpFailure::DELIVERY_FAILED, $this->issue());
        $this->assertFailed(OtpFailure::DELIVERY_FAILED, $this->issue());
        // Three failed sends used the person's hourly ceiling.
        $this->sms->failing = false;
        $this->assertFailed(OtpFailure::THROTTLED, $this->issue());
        $this->assertSame(3, $this->sms->attempts);
        $this->assertSame(3, AuthOtpChallenge::count());
    }

    public function test_the_unconfigured_default_sender_is_a_delivery_failure(): void
    {
        // The real default binding: nothing can be delivered.
        $this->app->forgetInstance(SmsSender::class);

        $this->assertFailed(OtpFailure::DELIVERY_FAILED, app(OtpChallenges::class)->issue(OtpPurpose::ACTIVATION, $this->person));
    }

    public function test_a_throttled_request_sends_nothing_and_creates_nothing(): void
    {
        config(['family_auth.throttle.person.hour' => 2]);

        $this->assertTrue($this->issue()->succeeded());
        $challenge = $this->issue()->challenge;
        $this->assertFailed(OtpFailure::THROTTLED, $this->issue());

        $this->assertSame(2, $this->sms->attempts);
        $this->assertSame(2, AuthOtpChallenge::count());
        // The open challenge is untouched by the blocked request.
        $this->assertNull($challenge->fresh()->superseded_at);

        // A resend is a send too.
        $this->travel(60)->seconds();
        $this->assertFailed(OtpFailure::THROTTLED, $this->otp->resend($challenge->uuid, OtpPurpose::ACTIVATION));
        $this->assertSame(1, $challenge->fresh()->send_count);
        $this->assertSame(2, $this->sms->attempts);
    }

    public function test_a_shared_destination_is_throttled_across_persons(): void
    {
        config(['family_auth.throttle.destination.hour' => 2]);
        $second = Person::factory()->create();
        $third = Person::factory()->create();
        $this->trustedMobile($second, self::MOBILE);
        $this->trustedMobile($third, self::MOBILE);

        $this->assertTrue($this->issue()->succeeded());
        $this->assertTrue($this->issue($second)->succeeded());
        $this->assertFailed(OtpFailure::THROTTLED, $this->issue($third));
        $this->assertSame(2, $this->sms->attempts);
    }

    public function test_policy_values_come_from_configuration(): void
    {
        config(['family_auth.otp' => [
            'digits' => 6, 'ttl_seconds' => 120, 'max_attempts' => 2, 'resend_cooldown_seconds' => 10, 'max_sends' => 2, 'grant_ttl_seconds' => 30,
        ]]);
        $challenge = $this->issue()->challenge;
        $this->assertEquals(now()->addSeconds(120), $challenge->fresh()->expires_at);

        $this->verify($challenge, $this->wrong($this->sms->lastCode()));
        $this->verify($challenge, $this->wrong($this->sms->lastCode()));
        $this->assertNotNull($challenge->fresh()->locked_at);

        $next = $this->issue()->challenge;
        $this->travel(10)->seconds();
        $this->assertTrue($this->otp->resend($next->uuid, OtpPurpose::ACTIVATION)->succeeded());
        $this->travel(10)->seconds();
        $this->assertFailed(OtpFailure::SEND_LIMIT, $this->otp->resend($next->uuid, OtpPurpose::ACTIVATION));
        $this->verify($next, $this->sms->lastCode());
        $this->assertEquals(now()->addSeconds(30), $next->fresh()->grant_expires_at);
    }

    public function test_the_service_has_no_method_that_returns_a_code(): void
    {
        foreach ((new ReflectionClass(OtpChallenges::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $type = $method->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertContains($type->getName(), [OtpResult::class, 'int'], $method->getName());
        }
    }
}
