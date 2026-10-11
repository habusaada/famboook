<?php

namespace Tests\Feature\ChangeRequests\ContactUpdate;

use App\Actions\RecordPersonDeathAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Actions\UpdatePersonAction;
use App\Contracts\SmsSender;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Models\AuthSecurityEvent;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\ContactUpdateHandler;
use App\Support\ChangeRequests\Handlers\PersonCorrectionHandler;
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use App\Support\FamilyAuth\CurrentTrustedMobile;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CONTACT_UPDATE V1 (docs/11 FP-ADR-079) end to end: the household head
 * changes a member's mobile with an OTP to the proposed number, or their
 * own with the current password and — when their number is TRUSTED — an
 * OTP to the old number too (R2; otherwise R1: no trust). SUBMITTED →
 * APPROVED by SYSTEM → APPLIED by SYSTEM, atomically. Synthetic data only;
 * codes are read from the fake SMS sender.
 */
class ContactUpdateTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/contact-updates';

    private const PASSWORD = 'password';

    private const HEAD_MOBILE = '0591234567';

    private const MEMBER_MOBILE = '0597654321';

    private const NEW_MOBILE = '0561112233';

    private FakeSmsSender $sms;

    /** @var array<string, mixed> */
    private array $head;

    private Person $member;

    private FamilyMembership $membership;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'change_requests.family_submission_mode' => 'GENERAL',
            'family_auth.contact_update_enabled' => true,
        ]);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::CONTACT_UPDATE->value => new ContactUpdateHandler,
            ChangeRequestType::PERSON_CORRECTION->value => new PersonCorrectionHandler,
            ChangeRequestType::RESIDENCE_UPDATE->value => new ResidenceUpdateHandler,
        ]));
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->head = $this->activatedHead();
        $this->trustedMobile($this->head['person'], self::HEAD_MOBILE);
        $this->member = Person::factory()->create(['full_name' => 'ابن الاختبار', 'mobile' => self::MEMBER_MOBILE]);
        $this->membership = FamilyMembership::factory()->create([
            'family_id' => $this->head['family']->id, 'person_id' => $this->member->id, 'is_household_head' => false, 'is_active' => true,
        ]);
    }

    private function ref(?FamilyMembership $membership = null): string
    {
        $membership ??= $this->membership;

        return HouseholdMemberReference::of($membership->family_id, $membership->id);
    }

    private function headRef(): string
    {
        return $this->ref($this->head['membership']);
    }

    private function api(string $method, string $uri = '', array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->head['user'])->fresh())->json($method, self::URI.$uri, $body);
    }

    private function start(array $body = [], ?User $as = null): TestResponse
    {
        return $this->api('POST', '', ['member_ref' => $this->ref(), 'mobile' => self::NEW_MOBILE, 'client_reference' => (string) Str::uuid(), ...$body], $as);
    }

    private function started(array $body = []): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->start($body)->assertCreated()->json('data.id'))->sole();
    }

    /** The last code delivered to one number. */
    private function codeFor(string $mobile): ?string
    {
        foreach (array_reverse($this->sms->sent) as $message) {
            if ($message->destination === $mobile && preg_match('/(?<!\d)(\d{6})(?!\d)/', $message->body, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function verify(ChangeRequest $request, ?string $code = null, ?string $oldCode = null): TestResponse
    {
        return $this->api('POST', "/{$request->uuid}/verify", array_filter(['code' => $code ?? $this->codeFor(self::NEW_MOBILE), 'old_code' => $oldCode]));
    }

    /** @return list<array{string, string, ?int}> event, actor side, actor */
    private function timeline(ChangeRequest $request): array
    {
        return WorkflowEvent::where('workflowable_id', $request->id)->orderBy('id')->get()
            ->map(fn (WorkflowEvent $e) => [$e->event_type->value, $e->actor_side->value, $e->actor_user_id])->all();
    }

    private function changeEvents(): array
    {
        return AuthSecurityEvent::where('event_type', 'CONTACT_MOBILE_CHANGED')->orderBy('id')->get()
            ->map(fn (AuthSecurityEvent $e) => [$e->outcome->value, $e->reason_code])->all();
    }

    // ---------------------------------------------------------------- a member's number

    public function test_a_member_number_is_changed_after_an_otp_to_the_proposed_number(): void
    {
        $response = $this->start()->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.verification.self', false)->assertJsonPath('data.verification.trust_transfer', false)
            ->assertJsonPath('data.verification.new_mobile.masked', '05*****233')->assertJsonPath('data.verification.old_mobile', null);
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();

        // One SMS, to the proposed number; nothing changed yet.
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(self::NEW_MOBILE, $this->sms->last()->destination);
        $this->assertSame(self::MEMBER_MOBILE, $this->member->fresh()->mobile);
        $this->assertEquals(['mobile' => self::NEW_MOBILE, 'self' => false, 'trust_transfer' => false], $request->submitted_data);

        $this->verify($request)->assertOk()->assertJsonPath('data.status', 'APPLIED')->assertJsonPath('data.trust_transferred', false);

        $this->assertSame(self::NEW_MOBILE, $this->member->fresh()->mobile);
        $this->assertSame([['SUBMITTED', 'FAMILY', $this->head['user']->id], ['APPROVED', 'SYSTEM', null], ['APPLIED', 'SYSTEM', null]], $this->timeline($request));
        $fresh = $request->fresh();
        $this->assertSame([null, null, null], [$fresh->approved_by, $fresh->applied_by, $fresh->reviewed_by]);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());
        $this->assertSame([['SUCCESS', null]], $this->changeEvents());
        $this->assertSame(['self' => false, 'trust_transferred' => false], AuthSecurityEvent::where('event_type', 'CONTACT_MOBILE_CHANGED')->sole()->metadata);
        // OTP proves access to the number — never trust, never an account.
        $this->assertSame(0, PersonMobileTrust::where('person_id', $this->member->id)->count());
        $this->assertSame(0, UserPersonLink::where('person_id', $this->member->id)->count());

        // A replay changes nothing.
        $this->verify($request, '000000')->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
    }

    public function test_a_member_trust_goes_stale_and_is_never_moved(): void
    {
        $this->trustedMobile($this->member, self::MEMBER_MOBILE);

        $request = $this->started();
        $this->verify($request)->assertOk()->assertJsonPath('data.trust_transferred', false);

        $this->assertSame(['STALE'], PersonMobileTrust::where('person_id', $this->member->id)->pluck('status')->map->value->all());
        $this->assertFalse(app(CurrentTrustedMobile::class)->for($this->member->fresh())->isTrusted());
    }

    public function test_shared_numbers_are_allowed_and_reveal_nothing(): void
    {
        // Somebody else — another Family — already has the number, trusted.
        $other = Person::factory()->create(['mobile' => self::NEW_MOBILE]);
        $this->trustedMobile($other, self::NEW_MOBILE);

        $response = $this->start()->assertCreated();
        $this->verify(ChangeRequest::where('uuid', $response->json('data.id'))->sole())->assertOk();

        $this->assertSame(self::NEW_MOBILE, $this->member->fresh()->mobile);
        $this->assertTrue(app(CurrentTrustedMobile::class)->for($other->fresh())->isTrusted(), 'the other Person keeps their trust');
        $this->assertStringNotContainsString((string) $other->full_name, (string) $response->getContent());
    }

    // ---------------------------------------------------------------- the head's own number

    public function test_the_head_needs_the_current_password(): void
    {
        $this->start(['member_ref' => $this->headRef(), 'current_password' => null])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->start(['member_ref' => $this->headRef(), 'current_password' => 'not the password'])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);

        $this->assertSame(0, ChangeRequest::count());
        $this->assertSame([], $this->sms->sent);
        $this->assertSame([['FAILURE', 'WRONG_CURRENT_PASSWORD']], $this->changeEvents());
    }

    public function test_r2_the_head_proves_the_password_the_old_and_the_new_number_and_the_trust_moves(): void
    {
        $oldTrust = PersonMobileTrust::where('person_id', $this->head['person']->id)->sole();
        $response = $this->start(['member_ref' => $this->headRef(), 'current_password' => self::PASSWORD])->assertCreated()
            ->assertJsonPath('data.verification.self', true)->assertJsonPath('data.verification.trust_transfer', true)
            ->assertJsonPath('data.verification.old_mobile.masked', '05*****567');
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();

        // Two codes: the proposed number and the CURRENT trusted number.
        $this->assertEqualsCanonicalizing([self::NEW_MOBILE, self::HEAD_MOBILE], array_map(fn ($m) => $m->destination, $this->sms->sent));

        // Both codes are required; one alone changes nothing.
        $this->verify($request)->assertUnprocessable()->assertJsonValidationErrors('old_code');
        $this->verify($request, null, '000000')->assertUnprocessable()->assertJsonValidationErrors('old_code');
        $this->assertSame(self::HEAD_MOBILE, $this->head['person']->fresh()->mobile);

        $this->verify($request, null, $this->codeFor(self::HEAD_MOBILE))->assertOk()
            ->assertJsonPath('data.status', 'APPLIED')->assertJsonPath('data.trust_transferred', true);

        $person = $this->head['person']->fresh();
        $this->assertSame(self::NEW_MOBILE, $person->mobile);
        $trusted = app(CurrentTrustedMobile::class)->for($person);
        $this->assertTrue($trusted->isTrusted());
        $this->assertSame([MobileVerificationMethod::SELF_OTP_TRANSFER, null], [$trusted->trust->verification_method, $trusted->trust->verified_by]);
        $this->assertSame(MobileTrustStatus::STALE, $oldTrust->fresh()->status);
        $this->assertSame([['SUCCESS', null]], $this->changeEvents());
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_GRANTED')->where('reason_code', 'SELF_OTP_TRANSFER')->count());

        // Password recovery now goes to the NEW number only.
        config(['family_auth.password_reset_enabled' => true, 'family_auth.activation.min_response_ms' => 0]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/family/auth/password/reset/start', ['national_id' => '123456789'])->assertOk();
        $this->assertSame(self::NEW_MOBILE, $this->sms->last()->destination);
    }

    public function test_r1_without_a_trusted_number_the_contact_changes_but_no_trust_is_granted(): void
    {
        PersonMobileTrust::where('person_id', $this->head['person']->id)->delete();

        $request = $this->started(['member_ref' => $this->headRef(), 'current_password' => self::PASSWORD]);
        $this->assertFalse($request->submitted_data['trust_transfer']);
        $this->assertSame([self::NEW_MOBILE], array_map(fn ($m) => $m->destination, $this->sms->sent));

        $this->verify($request)->assertOk()->assertJsonPath('data.trust_transferred', false);

        $person = $this->head['person']->fresh();
        $this->assertSame(self::NEW_MOBILE, $person->mobile);
        $this->assertSame('UNVERIFIED', app(CurrentTrustedMobile::class)->for($person)->state());
        $this->assertSame(0, PersonMobileTrust::where('person_id', $person->id)->count());
    }

    public function test_a_trust_revoked_after_the_start_blocks_the_r2_completion(): void
    {
        $request = $this->started(['member_ref' => $this->headRef(), 'current_password' => self::PASSWORD]);
        $oldCode = $this->codeFor(self::HEAD_MOBILE);
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('ADMINISTRATOR', 'web')));
        $trust = PersonMobileTrust::where('person_id', $this->head['person']->id)->where('status', MobileTrustStatus::TRUSTED->value)->sole();
        app(RevokePersonMobileTrustAction::class)->handle($admin, $trust, MobileTrustRevokeReason::REPORTED_LOST);

        $this->verify($request, null, $oldCode)->assertStatus(409)->assertJsonPath('code', 'CONTACT_UPDATE_FAILED');

        $this->assertSame(self::HEAD_MOBILE, $this->head['person']->fresh()->mobile);
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
    }

    // ---------------------------------------------------------------- codes, replay, concurrency

    public function test_wrong_codes_count_and_the_fifth_locks_and_closes_the_request(): void
    {
        $request = $this->started();
        for ($i = 0; $i < 4; $i++) {
            $this->verify($request, '000000')->assertUnprocessable()->assertJsonValidationErrors(['code' => 'رمز التحقق غير صحيح.']);
        }
        $this->verify($request, '000000')->assertStatus(423)->assertJsonPath('code', 'OTP_LOCKED');

        $this->assertSame(S::CANCELLED, $request->fresh()->status);
        $this->assertSame([['SUBMITTED', 'FAMILY', $this->head['user']->id], ['CANCELLED', 'SYSTEM', null]], $this->timeline($request));
        $this->verify($request)->assertStatus(409);
        $this->assertSame(self::MEMBER_MOBILE, $this->member->fresh()->mobile);
        $this->assertSame([['DENIED', 'VERIFICATION_FAILED']], $this->changeEvents());
    }

    public function test_a_code_works_only_for_its_own_request_and_number(): void
    {
        $first = $this->started();
        $firstCode = $this->codeFor(self::NEW_MOBILE);
        $other = FamilyMembership::factory()->create(['family_id' => $this->head['family']->id, 'is_active' => true]);
        $second = $this->started(['member_ref' => $this->ref($other), 'mobile' => '0569998877']);

        $this->verify($second, $firstCode)->assertUnprocessable();
        $this->assertSame(S::SUBMITTED, $first->fresh()->status);
        $this->verify($first, $firstCode)->assertOk();
    }

    public function test_a_newer_request_for_the_same_member_replaces_the_older_one(): void
    {
        $first = $this->started();
        $firstCode = $this->codeFor(self::NEW_MOBILE);
        $this->travel(61)->seconds();
        $second = $this->started(['mobile' => '0569998877']);

        $this->assertSame(S::CANCELLED, $first->fresh()->status);
        $this->verify($first, $firstCode)->assertStatus(409);
        $this->verify($second, $this->codeFor('0569998877'))->assertOk();
        $this->assertSame('0569998877', $this->member->fresh()->mobile);
        $this->assertContains(['DENIED', 'SUPERSEDED'], $this->changeEvents());
    }

    public function test_a_registry_change_after_the_start_refuses_and_closes_the_request(): void
    {
        $request = $this->started();
        app(UpdatePersonAction::class)->handle($this->member, ['mobile' => '0591110000'], $this->head['user']->id);

        $this->verify($request)->assertStatus(409)->assertJsonPath('code', 'CONTACT_UPDATE_FAILED');

        $this->assertSame('0591110000', $this->member->fresh()->mobile, 'the newer registry value is kept');
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
        $this->assertContains(['DENIED', 'NO_LONGER_APPLICABLE'], $this->changeEvents());
    }

    public function test_an_expired_request_is_closed_on_use_and_by_the_sweep(): void
    {
        $request = $this->started();
        $this->travel(31)->minutes();

        $this->verify($request)->assertStatus(410)->assertJsonPath('code', 'CONTACT_UPDATE_EXPIRED');
        $this->assertSame(S::CANCELLED, $request->fresh()->status);

        $this->travelBack();
        $abandoned = $this->started(['mobile' => '0569998877']);
        $this->travel(31)->minutes();
        $this->artisan('famboook:close-expired-contact-updates')->expectsOutput('Closed contact-update requests: 1')->assertSuccessful();
        $this->assertSame(S::CANCELLED, $abandoned->fresh()->status);
    }

    public function test_resend_follows_the_otp_rules_and_kills_the_previous_code(): void
    {
        $request = $this->started();
        $old = $this->codeFor(self::NEW_MOBILE);
        $this->api('POST', "/{$request->uuid}/resend", ['target' => 'new'])->assertStatus(429)->assertJsonPath('code', 'OTP_COOLDOWN');
        $this->api('POST', "/{$request->uuid}/resend", ['target' => 'old'])->assertNotFound();

        $this->travel(61)->seconds();
        $this->api('POST', "/{$request->uuid}/resend", ['target' => 'new'])->assertOk()->assertJsonPath('data.verification.new_mobile.masked', '05*****233');
        $this->assertCount(2, $this->sms->sent);
        $new = $this->codeFor(self::NEW_MOBILE);
        if ($new !== $old) {
            $this->verify($request, $old)->assertUnprocessable();
        }
        $this->verify($request, $new)->assertOk();
    }

    public function test_the_family_may_cancel_and_the_code_then_dies(): void
    {
        $request = $this->started();
        $this->actingAs($this->head['user']->fresh())->postJson("/api/v1/family/change-requests/{$request->uuid}/cancel")->assertOk();

        $this->verify($request)->assertStatus(409);
        $this->assertSame(self::MEMBER_MOBILE, $this->member->fresh()->mobile);
    }

    // ---------------------------------------------------------------- validation and boundaries

    public function test_invalid_unchanged_foreign_and_deceased_targets_are_refused(): void
    {
        // Refused starts count against the start limit too; raise it here.
        config(['family_auth.contact_update.limits.start_user_hour' => 50, 'family_auth.contact_update.limits.start_user_day' => 50]);
        $this->start(['mobile' => '0791234567'])->assertUnprocessable()->assertJsonValidationErrors(['mobile' => ContactUpdateHandler::INVALID_MOBILE]);
        $this->start(['mobile' => '05912'])->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->start(['mobile' => self::MEMBER_MOBILE])->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->start(['member_ref' => 'x'])->assertNotFound();
        $foreign = FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'is_active' => true]);
        $this->start(['member_ref' => $this->ref($foreign)])->assertNotFound();
        app(RecordPersonDeathAction::class)->handle($this->member, null, LifeStatusVerificationMethod::IN_PERSON, null);
        $this->start()->assertUnprocessable()->assertJsonValidationErrors('member_ref');

        $this->assertSame(0, ChangeRequest::count());
        $this->assertSame([], $this->sms->sent);
    }

    public function test_the_generic_submission_never_creates_a_contact_update(): void
    {
        $this->actingAs($this->head['user']->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'CONTACT_UPDATE', 'client_reference' => (string) Str::uuid(), 'data' => ['member_ref' => $this->ref(), 'mobile' => self::NEW_MOBILE],
        ])->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_the_gate_is_off_by_default_and_closed_means_nothing_happens(): void
    {
        $this->assertFalse((require config_path('family_auth.php'))['contact_update_enabled']);
        $request = $this->started();
        $sent = count($this->sms->sent);

        config(['family_auth.contact_update_enabled' => false]);
        $this->start()->assertStatus(503)->assertJsonPath('code', 'CONTACT_UPDATE_UNAVAILABLE');
        $this->verify($request)->assertStatus(503);
        $this->api('POST', "/{$request->uuid}/resend", ['target' => 'new'])->assertStatus(503);
        $this->assertCount($sent, $this->sms->sent);
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);

        // Type discovery offers it only while the gate is open.
        $types = fn () => array_column($this->actingAs($this->head['user']->fresh())->getJson('/api/v1/family/change-requests/types')->json('data'), 'type');
        $this->assertNotContains('CONTACT_UPDATE', $types());
        config(['family_auth.contact_update_enabled' => true]);
        $this->assertContains('CONTACT_UPDATE', $types());
    }

    public function test_starts_are_limited_per_user(): void
    {
        foreach (['0561110001', '0561110002', '0561110003'] as $mobile) {
            $this->start(['mobile' => $mobile])->assertCreated();
        }
        $this->start(['mobile' => '0561110004'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertCount(3, $this->sms->sent);
    }

    public function test_contact_updates_have_their_own_sms_quota_and_never_use_up_activation_or_reset(): void
    {
        config(['family_auth.throttle.contact_update.hour' => 1, 'family_auth.password_reset_enabled' => true, 'family_auth.activation.min_response_ms' => 0]);

        $this->start()->assertCreated();
        $other = FamilyMembership::factory()->create(['family_id' => $this->head['family']->id, 'is_active' => true]);
        $this->start(['member_ref' => $this->ref($other), 'mobile' => '0569998877'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, ChangeRequest::where('status', S::SUBMITTED->value)->count(), 'the refused request is closed at once');

        // The quota is the contact update's own: password reset still sends.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/family/auth/password/reset/start', ['national_id' => '123456789'])->assertOk();
        $this->assertCount(2, $this->sms->sent);
        $this->assertSame(self::HEAD_MOBILE, $this->sms->last()->destination);
    }

    public function test_the_global_ceiling_still_caps_contact_updates(): void
    {
        config(['family_auth.throttle.global.hour' => 0]);

        $this->start()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertSame([], $this->sms->sent);
    }

    public function test_another_family_cannot_see_or_complete_the_request(): void
    {
        $request = $this->started();
        $stranger = $this->activatedHead('223456789')['user'];

        $this->api('GET', "/{$request->uuid}", [], $stranger)->assertNotFound();
        $this->api('POST', "/{$request->uuid}/verify", ['code' => $this->codeFor(self::NEW_MOBILE)], $stranger)->assertNotFound();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_staff_see_the_history_read_only_and_cannot_move_it(): void
    {
        $request = $this->started();
        $reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));

        $detail = $this->actingAs($reviewer)->getJson("/api/v1/change-requests/{$request->uuid}")->assertOk()
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.presentation.rows.1', ['label' => 'رقم الجوال', 'current' => '05*****321', 'proposed' => '05*****233']);
        $this->assertStringNotContainsString(self::NEW_MOBILE, (string) $detail->getContent());
        $this->actingAs($reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/start-review")->assertStatus(409);
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_no_number_code_or_password_leaks(): void
    {
        $start = $this->start(['member_ref' => $this->headRef(), 'current_password' => self::PASSWORD])->assertCreated();
        $request = ChangeRequest::where('uuid', $start->json('data.id'))->sole();
        $codes = [$this->codeFor(self::NEW_MOBILE), $this->codeFor(self::HEAD_MOBILE)];
        $done = $this->verify($request, null, $codes[1])->assertOk();

        $stored = json_encode([AuthSecurityEvent::all()->toArray(), WorkflowEvent::all()->toArray(), FamilyActivity::all()->toArray()]);
        foreach ([self::NEW_MOBILE, self::HEAD_MOBILE, self::PASSWORD.'"', ...$codes] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $stored, $secret);
            $this->assertStringNotContainsString($secret, implode("\n", $this->logged), $secret);
            $this->assertStringNotContainsString($secret, (string) $start->getContent().$done->getContent(), $secret);
        }
    }

    public function test_it_is_a_production_type_reachable_only_through_its_gate(): void
    {
        $this->assertSame(ContactUpdateHandler::class, ChangeRequestTypes::PRODUCTION['CONTACT_UPDATE'] ?? null);
        $this->assertTrue(ChangeRequestTypes::production()->has(ChangeRequestType::CONTACT_UPDATE));
        // Registered, yet still closed by default: the gate decides.
        $this->assertFalse((require config_path('family_auth.php'))['contact_update_enabled']);
    }
}
