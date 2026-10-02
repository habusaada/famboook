<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\GrantPersonMobileTrustAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Actions\UpdatePersonAction;
use App\Enums\LifeStatus;
use App\Enums\MobileTrustDenial;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Enums\UserPersonLinkStatus;
use App\Exceptions\MobileTrustException;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\CurrentTrustedMobile;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1E: the trusted-mobile resolver and the grant / revoke / stale
 * lifecycle (docs/05 §53b). Trust belongs to a Person and one exact
 * normalized number. Synthetic data only.
 */
class MobileTrustLifecycleTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const MOBILE = '0591234567';

    private const OTHER = '0567654321';

    private CurrentTrustedMobile $trusted;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        config(['session.driver' => 'database']);
        $this->trusted = app(CurrentTrustedMobile::class);
        $this->admin = $this->staff('ADMINISTRATOR');
    }

    private function staff(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
    }

    private function person(?string $mobile = self::MOBILE, array $attributes = []): Person
    {
        return Person::factory()->create(['mobile' => $mobile, ...$attributes]);
    }

    private function grant(Person $person, ?User $actor = null, MobileVerificationMethod $method = MobileVerificationMethod::IN_PERSON): PersonMobileTrust
    {
        return app(GrantPersonMobileTrustAction::class)->handle($actor ?? $this->admin, $person, $method);
    }

    private function revoke(PersonMobileTrust $trust, ?User $actor = null, MobileTrustRevokeReason $reason = MobileTrustRevokeReason::REPORTED_LOST): PersonMobileTrust
    {
        return app(RevokePersonMobileTrustAction::class)->handle($actor ?? $this->admin, $trust, $reason);
    }

    private function changeMobile(Person $person, ?string $mobile): Person
    {
        return app(UpdatePersonAction::class)->handle($person->fresh(), ['mobile' => $mobile], $this->admin->id);
    }

    private function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (MobileTrustException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }
        $this->fail("The operation was not refused with {$reason}.");
    }

    /** @return list<string> */
    private function events(): array
    {
        return AuthSecurityEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all();
    }

    // --------------------------------------------------------------- resolver

    public function test_an_imported_mobile_is_unverified_until_it_is_granted(): void
    {
        $person = $this->person();

        // Presence is not trust: no row exists for a stored mobile.
        $result = $this->trusted->for($person);
        $this->assertFalse($result->isTrusted());
        $this->assertSame(MobileTrustDenial::UNVERIFIED, $result->denial);
        $this->assertSame('UNVERIFIED', $result->state());
        $this->assertNull($result->trust);
        $this->assertNull($result->destination);
        $this->assertSame(0, PersonMobileTrust::count());
    }

    public function test_the_resolver_succeeds_only_for_the_exact_current_trusted_number(): void
    {
        $person = $this->person(' ٠٥٩-١٢٣ ٤٥٦٧ ');
        $trust = $this->grant($person);

        $result = $this->trusted->for($person->fresh());
        $this->assertTrue($result->isTrusted());
        $this->assertSame('TRUSTED', $result->state());
        $this->assertTrue($result->trust->is($trust));
        // The normalized destination, in memory only.
        $this->assertSame(self::MOBILE, $result->destination);

        // A direct write that bypassed the model: the number no longer matches.
        DB::table('persons')->where('id', $person->id)->update(['mobile' => self::OTHER]);
        $stale = $this->trusted->for($person->fresh());
        $this->assertSame(MobileTrustDenial::STALE, $stale->denial);
        $this->assertNull($stale->destination);
        // The row still says TRUSTED, and still nothing is trusted.
        $this->assertSame(MobileTrustStatus::TRUSTED, $trust->fresh()->status);
    }

    public function test_a_person_without_a_valid_mobile_has_no_trusted_mobile(): void
    {
        foreach ([null, '', '591234567', '+970591234567', '0591234567 / 0561234567', 'abc'] as $mobile) {
            $person = $this->person(self::MOBILE);
            $this->grant($person);
            DB::table('persons')->where('id', $person->id)->update(['mobile' => $mobile]);

            $result = $this->trusted->for($person->fresh());
            $this->assertSame(MobileTrustDenial::NO_VALID_MOBILE, $result->denial, json_encode($mobile));
            $this->assertSame('NO_MOBILE', $result->state());
        }
    }

    public function test_a_missing_fingerprint_key_fails_closed(): void
    {
        $person = $this->person();
        $this->grant($person);
        config(['family_auth.fingerprint.key' => null]);

        $result = $this->trusted->for($person->fresh());
        $this->assertSame(MobileTrustDenial::FINGERPRINT_UNAVAILABLE, $result->denial);
        $this->assertSame('UNAVAILABLE', $result->state());
        $this->assertNull($result->destination);
    }

    public function test_the_resolver_takes_only_a_person_and_records_nothing(): void
    {
        $parameters = (new ReflectionMethod(CurrentTrustedMobile::class, 'for'))->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame(Person::class, $parameters[0]->getType()->getName());

        $person = $this->person();
        $this->grant($person);
        $before = AuthSecurityEvent::count();
        $this->trusted->for($person->fresh());
        $this->trusted->for($this->person(null));
        $this->assertSame($before, AuthSecurityEvent::count());
    }

    // ------------------------------------------------------------------ grant

    public function test_super_admin_and_administrator_can_grant(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $i => $role) {
            $actor = $this->staff($role);
            $person = $this->person('059123456'.$i);

            $trust = $this->grant($person, $actor, MobileVerificationMethod::STAFF_CALLBACK)->fresh();

            $this->assertSame(MobileTrustStatus::TRUSTED, $trust->status);
            $this->assertSame(MobileVerificationMethod::STAFF_CALLBACK, $trust->verification_method);
            $this->assertSame($actor->id, $trust->verified_by);
            $this->assertNotNull($trust->verified_at);
            $this->assertSame('6'.$i, $trust->mobile_last2);
            $this->assertSame(1, $trust->key_version);
            // A fingerprint, never the number; assistance is a separate concept.
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $trust->mobile_fingerprint);
            $this->assertStringNotContainsString('059123456', $trust->mobile_fingerprint);
            $this->assertNull($trust->assisted_by);
            $this->assertNull($trust->assisted_at);
            $this->assertTrue($this->trusted->for($person->fresh())->isTrusted());
        }
    }

    public function test_only_staff_side_holders_of_the_permission_can_grant(): void
    {
        $person = $this->person();
        $actors = [];
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $actors[$role] = $this->staff($role);
        }
        // Family-side accounts, even holding the permission directly.
        $actors['coordinator'] = tap($this->familyUser(['FAMILY_USER', 'COORDINATOR']), fn (User $u) => $u->givePermissionTo('person-mobile-trust.grant'));
        $actors['family'] = tap($this->familyUser(), fn (User $u) => $u->givePermissionTo('person-mobile-trust.grant'));
        // Role-less and custom-role accounts with a direct permission.
        $actors['none'] = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('person-mobile-trust.grant'));
        $custom = Role::create(['name' => 'SYNTHETIC_CUSTOM_ROLE', 'guard_name' => 'web']);
        $custom->givePermissionTo('person-mobile-trust.grant');
        $actors['custom'] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($custom));
        // A mixed account and an inactive administrator.
        $actors['mixed'] = $this->familyUser(['ADMINISTRATOR', 'FAMILY_USER']);
        $actors['inactive'] = tap(User::factory()->create(['is_active' => false]), fn (User $u) => $u->assignRole('ADMINISTRATOR'));

        foreach ($actors as $label => $actor) {
            try {
                $this->grant($person, $actor);
                $this->fail("{$label} granted a trusted mobile.");
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(0, PersonMobileTrust::count());
        $this->assertSame([], $this->events());
    }

    public function test_grant_reads_the_current_mobile_and_accepts_no_number(): void
    {
        $parameters = array_map(
            fn ($p) => $p->getType()->getName(),
            (new ReflectionMethod(GrantPersonMobileTrustAction::class, 'handle'))->getParameters(),
        );

        // Actor, Person, method — there is no way to pass a mobile number.
        $this->assertSame([User::class, Person::class, MobileVerificationMethod::class], $parameters);
    }

    public function test_grant_refuses_an_absent_or_invalid_current_mobile(): void
    {
        foreach ([null, '', '591234567', '+970591234567', '05912345', '0591234567 / 0561234567', 'no phone'] as $mobile) {
            $person = $this->person($mobile);
            $this->assertRefused(MobileTrustException::NO_VALID_MOBILE, fn () => $this->grant($person));
        }
        $this->assertSame(0, PersonMobileTrust::count());
    }

    public function test_grant_refuses_a_deleted_inactive_or_non_alive_person(): void
    {
        $deleted = $this->person();
        $deleted->delete();
        $cases = [
            $deleted,
            $this->person(self::MOBILE, ['is_active' => false]),
            $this->person(self::MOBILE, ['life_status' => LifeStatus::DECEASED->value]),
            $this->person(self::MOBILE, ['life_status' => LifeStatus::UNKNOWN->value]),
        ];
        foreach ($cases as $person) {
            $this->assertRefused(MobileTrustException::PERSON_NOT_ELIGIBLE, fn () => $this->grant($person));
        }
        $this->assertSame(0, PersonMobileTrust::count());
    }

    public function test_grant_does_not_require_a_household_head_or_any_membership(): void
    {
        // A Person with no family membership at all.
        $person = $this->person();

        $this->assertSame(MobileTrustStatus::TRUSTED, $this->grant($person)->status);
    }

    public function test_a_shared_mobile_is_trusted_independently_per_person(): void
    {
        $a = $this->person(self::MOBILE);
        $b = $this->person(self::MOBILE);
        $c = $this->person('059-123-4567');

        $trustA = $this->grant($a);

        // Trust for A does not trust B or C.
        $this->assertTrue($this->trusted->for($a->fresh())->isTrusted());
        $this->assertSame(MobileTrustDenial::UNVERIFIED, $this->trusted->for($b->fresh())->denial);
        $this->assertSame(MobileTrustDenial::UNVERIFIED, $this->trusted->for($c->fresh())->denial);

        $trustB = $this->grant($b);
        // The same number, the same fingerprint, two independent rows.
        $this->assertSame($trustA->mobile_fingerprint, $trustB->mobile_fingerprint);
        $this->assertNotSame($trustA->id, $trustB->id);

        // Revoking A does not revoke B.
        $this->revoke($trustA);
        $this->assertSame(MobileTrustDenial::REVOKED, $this->trusted->for($a->fresh())->denial);
        $this->assertTrue($this->trusted->for($b->fresh())->isTrusted());
        $this->assertSame(MobileTrustDenial::UNVERIFIED, $this->trusted->for($c->fresh())->denial);
    }

    public function test_a_duplicate_grant_of_the_current_number_is_a_conflict(): void
    {
        $person = $this->person();
        $first = $this->grant($person, $this->admin, MobileVerificationMethod::IN_PERSON)->fresh();
        $other = $this->staff('SUPER_ADMIN');

        $this->assertRefused(
            MobileTrustException::ALREADY_TRUSTED,
            fn () => $this->grant($person, $other, MobileVerificationMethod::AUTHORIZED_RECORD_REVIEW),
        );
        $this->assertSame(409, (new MobileTrustException(MobileTrustException::ALREADY_TRUSTED))->render(request())->status());

        // Verifier, method and time are never rewritten.
        $this->assertSame(1, PersonMobileTrust::count());
        $after = $first->fresh();
        $this->assertSame($this->admin->id, $after->verified_by);
        $this->assertSame(MobileVerificationMethod::IN_PERSON, $after->verification_method);
        $this->assertEquals($first->verified_at, $after->verified_at);
        $this->assertSame(['MOBILE_TRUST_GRANTED'], $this->events());
    }

    // ----------------------------------------------------------------- revoke

    public function test_revoke_makes_the_trust_unusable_and_keeps_the_row(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);
        $open = AuthOtpChallenge::factory()->create(['person_id' => $person->id, 'mobile_trust_id' => $trust->id]);

        $revoked = $this->revoke($trust, $this->admin, MobileTrustRevokeReason::NOT_OWNER)->fresh();

        $this->assertSame(MobileTrustStatus::REVOKED, $revoked->status);
        $this->assertSame($this->admin->id, $revoked->revoked_by);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('NOT_OWNER', $revoked->revoke_reason);
        // The verification that was granted is still on record.
        $this->assertNotNull($revoked->verified_at);
        $this->assertSame(1, PersonMobileTrust::count());
        $this->assertSame(MobileTrustDenial::REVOKED, $this->trusted->for($person->fresh())->denial);
        // Its open OTP challenge is unusable at once.
        $this->assertNotNull($open->fresh()->superseded_at);
        $this->assertSame(['MOBILE_TRUST_GRANTED', 'MOBILE_TRUST_REVOKED'], $this->events());
        $this->assertSame('NOT_OWNER', AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_REVOKED')->sole()->reason_code);
    }

    public function test_only_staff_side_holders_of_the_permission_can_revoke(): void
    {
        $trust = $this->grant($this->person());
        $actors = ['reviewer' => $this->staff('REVIEWER'), 'data entry' => $this->staff('DATA_ENTRY')];
        $actors['coordinator'] = tap($this->familyUser(['FAMILY_USER', 'COORDINATOR']), fn (User $u) => $u->givePermissionTo('person-mobile-trust.revoke'));
        $actors['none'] = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('person-mobile-trust.revoke'));

        foreach ($actors as $label => $actor) {
            try {
                $this->revoke($trust, $actor);
                $this->fail("{$label} revoked a trusted mobile.");
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(MobileTrustStatus::TRUSTED, $trust->fresh()->status);

        $this->revoke($trust, $this->staff('SUPER_ADMIN'));
        $this->assertSame(MobileTrustStatus::REVOKED, $trust->fresh()->status);
    }

    public function test_only_a_trusted_row_can_be_revoked(): void
    {
        $trust = $this->grant($this->person());
        $this->revoke($trust);

        $this->assertRefused(MobileTrustException::NOT_TRUSTED, fn () => $this->revoke($trust));
        $this->assertSame(409, (new MobileTrustException(MobileTrustException::NOT_TRUSTED))->render(request())->status());
    }

    public function test_revoke_does_not_touch_the_account_the_link_or_the_sessions(): void
    {
        $a = $this->activatedHead('123456789');
        $a['person']->forceFill(['mobile' => self::MOBILE])->save();
        $trust = $this->grant($a['person']);
        $this->sessionRowFor($a['user']);

        $this->revoke($trust);

        // Mobile trust is destination trust, not identity or session validity.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertTrue($a['user']->fresh()->is_active);
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);
        $this->assertSame('ACTIVE', $a['identity']->fresh()->status->value);
        $this->assertNotContains('SESSIONS_REVOKED', $this->events());
    }

    public function test_a_grant_after_a_revoke_is_a_new_history_row(): void
    {
        $person = $this->person();
        $first = $this->grant($person);
        $this->revoke($first);

        $second = $this->grant($person, $this->admin, MobileVerificationMethod::AUTHORIZED_RECORD_REVIEW);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(MobileTrustStatus::REVOKED, $first->fresh()->status);
        $this->assertSame(MobileTrustStatus::TRUSTED, $second->fresh()->status);
        $this->assertSame(2, $person->mobileTrusts()->count());
        $this->assertTrue($this->trusted->for($person->fresh())->trust->is($second));
    }

    // ------------------------------------------------------------------ stale

    public function test_changing_the_mobile_makes_the_previous_trust_stale(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);
        $open = AuthOtpChallenge::factory()->create(['person_id' => $person->id, 'mobile_trust_id' => $trust->id]);

        $this->changeMobile($person, self::OTHER);

        $stale = $trust->fresh();
        $this->assertSame(MobileTrustStatus::STALE, $stale->status);
        $this->assertNotNull($stale->stale_at);
        // The open OTP challenge for the old number is superseded.
        $this->assertNotNull($open->fresh()->superseded_at);
        $this->assertSame(MobileTrustDenial::STALE, $this->trusted->for($person->fresh())->denial);
        $this->assertSame(['MOBILE_TRUST_GRANTED', 'MOBILE_TRUST_STALE'], $this->events());
        $event = AuthSecurityEvent::where('event_type', 'MOBILE_TRUST_STALE')->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame($trust->id, $event->mobile_trust_id);
    }

    public function test_changing_back_to_the_old_number_never_restores_the_trust(): void
    {
        $person = $this->person();
        $old = $this->grant($person);
        $this->changeMobile($person, self::OTHER);

        $this->changeMobile($person, self::MOBILE);

        // The number is the trusted one again; the trust is not.
        $this->assertSame(MobileTrustStatus::STALE, $old->fresh()->status);
        $result = $this->trusted->for($person->fresh());
        $this->assertFalse($result->isTrusted());
        $this->assertSame(MobileTrustDenial::STALE, $result->denial);
        $this->assertNull($result->destination);

        // A new verification is required — and is a new row.
        $new = $this->grant($person);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(MobileTrustStatus::STALE, $old->fresh()->status);
        $this->assertTrue($this->trusted->for($person->fresh())->trust->is($new));
    }

    public function test_a_formatting_only_edit_keeps_the_trust(): void
    {
        $person = $this->person(self::MOBILE);
        $trust = $this->grant($person);
        $open = AuthOtpChallenge::factory()->create(['person_id' => $person->id, 'mobile_trust_id' => $trust->id]);

        foreach (['059-123-4567', ' 059 123 4567 ', '٠٥٩١٢٣٤٥٦٧'] as $sameNumber) {
            $this->changeMobile($person, $sameNumber);

            $this->assertSame(MobileTrustStatus::TRUSTED, $trust->fresh()->status);
            $this->assertNull($open->fresh()->superseded_at);
            $this->assertTrue($this->trusted->for($person->fresh())->isTrusted());
        }
        $this->assertSame(['MOBILE_TRUST_GRANTED'], $this->events());
    }

    public function test_removing_the_mobile_makes_the_trust_stale(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);

        $this->changeMobile($person, null);

        $this->assertSame(MobileTrustStatus::STALE, $trust->fresh()->status);
        $this->assertSame(MobileTrustDenial::NO_VALID_MOBILE, $this->trusted->for($person->fresh())->denial);
    }

    public function test_any_eloquent_write_path_makes_the_trust_stale(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);

        // Not UpdatePersonAction: a plain model save.
        $person->fresh()->forceFill(['mobile' => self::OTHER])->save();

        $this->assertSame(MobileTrustStatus::STALE, $trust->fresh()->status);
    }

    public function test_unrelated_person_saves_and_creation_touch_no_trust(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);
        $events = AuthSecurityEvent::count();

        // Another field changes; the mobile does not.
        app(UpdatePersonAction::class)->handle($person->fresh(), ['notes' => 'ملاحظة'], $this->admin->id);
        $person->fresh()->forceFill(['alternate_mobile' => self::OTHER])->save();
        // Creating a Person with a mobile creates no trust and no event.
        $this->person(self::OTHER);

        $this->assertSame(MobileTrustStatus::TRUSTED, $trust->fresh()->status);
        $this->assertSame(1, PersonMobileTrust::count());
        $this->assertSame($events, AuthSecurityEvent::count());
    }

    public function test_a_grant_for_a_new_number_stales_a_trust_the_hook_never_saw(): void
    {
        $person = $this->person();
        $old = $this->grant($person);
        // The number changed behind the model's back.
        DB::table('persons')->where('id', $person->id)->update(['mobile' => self::OTHER]);

        $new = $this->grant($person->fresh());

        $this->assertSame(MobileTrustStatus::STALE, $old->fresh()->status);
        $this->assertSame(MobileTrustStatus::TRUSTED, $new->fresh()->status);
        $this->assertSame(self::OTHER, $this->trusted->for($person->fresh())->destination);
    }

    public function test_a_stale_trust_follows_its_transaction(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);

        try {
            DB::transaction(function () use ($person) {
                $this->changeMobile($person, self::OTHER);
                throw new \RuntimeException('the surrounding action failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(self::MOBILE, $person->fresh()->mobile);
        $this->assertSame(MobileTrustStatus::TRUSTED, $trust->fresh()->status);
        $this->assertSame(['MOBILE_TRUST_GRANTED'], $this->events());
    }

    public function test_security_events_never_carry_the_mobile_number(): void
    {
        $person = $this->person();
        $trust = $this->grant($person);
        $this->changeMobile($person, self::OTHER);
        $this->revoke($this->grant($person->fresh()));

        foreach (AuthSecurityEvent::all() as $event) {
            $json = json_encode($event->getAttributes());
            $this->assertStringNotContainsString(self::MOBILE, $json);
            $this->assertStringNotContainsString(self::OTHER, $json);
            $this->assertStringNotContainsString($trust->mobile_fingerprint, $json);
        }
        $this->assertSame(
            ['MOBILE_TRUST_GRANTED', 'MOBILE_TRUST_STALE', 'MOBILE_TRUST_GRANTED', 'MOBILE_TRUST_REVOKED'],
            $this->events(),
        );
    }
}
