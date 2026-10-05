<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\CorrectNationalIdAction;
use App\Actions\RecordPersonDeathAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\FamilyAccessDenial;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkStatus;
use App\Exceptions\FamilyIdentityException;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyActivity;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1D: the registry actions keep the Family Portal identity true
 * (docs/11 §30a) — a National ID correction rotates the login identifier in
 * the same transaction, and a recorded death ends the link. Synthetic data.
 */
class IdentityRegistryIntegrationTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private FamilyAccessResolver $resolver;

    private FamilyAuthIdentities $identities;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        config(['session.driver' => 'database']);
        $this->resolver = app(FamilyAccessResolver::class);
        $this->identities = app(FamilyAuthIdentities::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMINISTRATOR');
    }

    private function correct(Person $person, string $nationalId): Person
    {
        return app(CorrectNationalIdAction::class)->handle($person, $nationalId, $this->admin->id);
    }

    /** @return list<string> */
    private function events(): array
    {
        return AuthSecurityEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all();
    }

    // -------------------------------------------------- National ID correction

    public function test_a_valid_different_national_id_rotates_the_login_identifier(): void
    {
        $a = $this->activatedHead('123456789');
        $this->sessionRowFor($a['user']);

        $this->correct($a['person'], '987654321');

        $old = $a['identity']->fresh();
        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $old->status);
        $this->assertSame(AuthIdentitySupersedeReason::NATIONAL_ID_CORRECTED, $old->supersede_reason);
        $this->assertNotNull($old->superseded_at);

        $new = $this->identities->current($a['user']);
        $this->assertFalse($new->is($old));
        $this->assertSame(AuthIdentityStatus::ACTIVE, $new->status);

        // The old National ID stops authenticating; the new one works.
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        $this->assertTrue($this->identities->findByNationalIdInput('987654321')->is($new));
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());

        // Same person, same password: the sessions are kept.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);

        $this->assertSame(['LOGIN_IDENTIFIER_ROTATED'], $this->events());
        $event = AuthSecurityEvent::sole();
        $this->assertSame('SUCCESS', $event->outcome->value);
        $this->assertSame('NATIONAL_ID_CORRECTED', $event->reason_code);
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $json = json_encode($event->getAttributes());
        $this->assertStringNotContainsString('123456789', $json);
        $this->assertStringNotContainsString('987654321', $json);
    }

    public function test_a_formatting_only_correction_keeps_the_same_identity(): void
    {
        $a = $this->activatedHead('123456789');
        $this->sessionRowFor($a['user']);

        // A different stored value, the same nine digits, the same key.
        $this->correct($a['person'], '123-456-789');

        $this->assertSame('123-456-789', $a['person']->fresh()->national_id);
        $this->assertSame(1, FamilyAuthIdentity::count());
        $this->assertSame(AuthIdentityStatus::ACTIVE, $a['identity']->fresh()->status);
        $this->assertTrue($this->identities->findByNationalIdInput('123456789')->is($a['identity']));
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertSame([], $this->events());
    }

    public function test_an_unchanged_value_still_returns_early(): void
    {
        $a = $this->activatedHead('123456789');

        $this->correct($a['person'], '123456789');

        $this->assertSame(1, FamilyAuthIdentity::count());
        $this->assertSame([], $this->events());
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_a_value_that_is_not_nine_digits_suspends_the_identity_and_ends_sessions(): void
    {
        $a = $this->activatedHead('123456789');
        $this->sessionRowFor($a['user']);

        $this->correct($a['person'], 'SYN-400333444');

        $identity = $a['identity']->fresh();
        $this->assertSame(AuthIdentityStatus::SUSPENDED, $identity->status);
        $this->assertNull($identity->supersede_reason);
        $this->assertSame(1, FamilyAuthIdentity::count());
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        // The link and the account are untouched; the identity alone is invalid.
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);
        $this->assertTrue($a['user']->fresh()->is_active);
        $this->assertSame(FamilyAccessDenial::AUTH_IDENTITY_SUSPENDED, $this->resolver->identity($a['user']->fresh())->denial);
        $this->assertSame(['SESSIONS_REVOKED', 'LOGIN_IDENTIFIER_ROTATED'], $this->events());
        $this->assertSame('FAILURE', AuthSecurityEvent::where('event_type', 'LOGIN_IDENTIFIER_ROTATED')->sole()->outcome->value);
    }

    public function test_a_later_valid_correction_restores_a_suspended_identity(): void
    {
        $a = $this->activatedHead('123456789');
        $this->correct($a['person'], 'SYN-400333444');

        $this->correct($a['person']->fresh(), '987654321');

        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $a['identity']->fresh()->status);
        $current = $this->identities->current($a['user']);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $current->status);
        $this->assertTrue($this->identities->findByNationalIdInput('987654321')->is($current));
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());
    }

    public function test_a_login_key_collision_rolls_the_whole_correction_back(): void
    {
        $a = $this->activatedHead('123456789');
        $b = $this->activatedHead('987654321');
        // The registry stores B's National ID in another format, so the exact
        // duplicate guard does not see it — the login key still collides.
        DB::table('persons')->where('id', $b['person']->id)->update(['national_id' => '987-654-321']);
        $this->sessionRowFor($a['user']);

        try {
            $this->correct($a['person'], '987654321');
            $this->fail('A correction that collides with another login key was accepted.');
        } catch (FamilyIdentityException $e) {
            $this->assertSame(FamilyIdentityException::LOGIN_KEY_TAKEN, $e->reason);
            $this->assertSame(422, $e->render(request())->status());
            $this->assertStringNotContainsString('987654321', $e->getMessage());
        }

        // Canonical National ID and auth identity are exactly as before.
        $this->assertSame('123456789', $a['person']->fresh()->national_id);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $a['identity']->fresh()->status);
        $this->assertSame(2, FamilyAuthIdentity::count());
        $this->assertTrue($this->identities->findByNationalIdInput('123456789')->is($a['identity']));
        $this->assertTrue($this->identities->findByNationalIdInput('987654321')->is($b['identity']));
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());
        $this->assertTrue($this->resolver->familyContext($b['user']->fresh())->hasFamilyContext());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertSame([], $this->events());
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::NATIONAL_ID_CORRECTED->value)->count());
    }

    public function test_the_collision_is_an_explicit_failure_through_the_api(): void
    {
        $a = $this->activatedHead('123456789');
        $b = $this->activatedHead('987654321');
        DB::table('persons')->where('id', $b['person']->id)->update(['national_id' => '987-654-321']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/people/{$a['person']->person_code}/national-id", [
            'national_id' => '987654321', 'national_id_confirmation' => '987654321',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('national_id');
        $this->assertStringNotContainsString('987654321', $response->getContent());
        $this->assertSame('123456789', $a['person']->fresh()->national_id);
    }

    public function test_a_direct_database_change_is_detected_as_a_mismatch(): void
    {
        // A write that bypassed CorrectNationalIdAction: the identity was not
        // synchronized, so the resolver refuses it and the obsolete
        // identifier resolves to an account that can no longer be used.
        $a = $this->activatedHead('123456789');
        DB::table('persons')->where('id', $a['person']->id)->update(['national_id' => '987654321']);

        $this->assertSame(FamilyAccessDenial::IDENTITY_MISMATCH, $this->resolver->identity($a['user']->fresh())->denial);
        $this->assertNull($this->identities->findByNationalIdInput('987654321'));
        $stale = $this->identities->findByNationalIdInput('123456789');
        $this->assertFalse($this->identities->isConsistent($stale, $a['person']->fresh()));
    }

    public function test_a_person_without_a_link_is_corrected_exactly_as_before(): void
    {
        [$person] = $this->eligibleHead('123456789');

        $this->correct($person, '987654321');

        $this->assertSame('987654321', $person->fresh()->national_id);
        $this->assertSame(0, FamilyAuthIdentity::count());
        $this->assertSame([], $this->events());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::NATIONAL_ID_CORRECTED->value)->count());
    }

    public function test_a_suspended_link_still_has_its_identity_synchronized(): void
    {
        $a = $this->activatedHead('123456789');
        $a['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $this->correct($a['person'], '987654321');

        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $a['identity']->fresh()->status);
        $this->assertTrue($this->identities->isConsistent($this->identities->current($a['user']), $a['person']->fresh()));
    }

    public function test_an_ended_link_has_no_identity_to_synchronize(): void
    {
        $a = $this->activatedHead('123456789');
        $a['link']->forceFill(['status' => UserPersonLinkStatus::ENDED->value, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save();

        $this->correct($a['person'], '987654321');

        $this->assertSame('987654321', $a['person']->fresh()->national_id);
        $this->assertSame(1, FamilyAuthIdentity::count());
        $this->assertSame([], $this->events());
    }

    // ------------------------------------------------------------------ death

    public function test_a_recorded_death_ends_the_link_and_supersedes_the_identity(): void
    {
        $a = $this->activatedHead('123456789');
        $this->sessionRowFor($a['user']);
        $this->sessionRowFor($a['user']);

        app(RecordPersonDeathAction::class)->handle($a['person'], null, LifeStatusVerificationMethod::IN_PERSON, $this->admin->id);

        $this->assertSame(LifeStatus::DECEASED, $a['person']->fresh()->life_status);

        $link = $a['link']->fresh();
        $this->assertSame(UserPersonLinkStatus::ENDED, $link->status);
        $this->assertSame('PERSON_DECEASED', $link->end_reason);
        $this->assertSame($this->admin->id, $link->ended_by);

        $identity = $a['identity']->fresh();
        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $identity->status);
        $this->assertSame(AuthIdentitySupersedeReason::LINK_ENDED, $identity->supersede_reason);
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertSame(['SESSIONS_REVOKED', 'LINK_ENDED'], $this->events());
        $this->assertSame('PERSON_DECEASED', AuthSecurityEvent::where('event_type', 'LINK_ENDED')->sole()->reason_code);
        $this->assertSame(FamilyAccessDenial::NO_LINK, $this->resolver->identity($a['user']->fresh())->denial);
    }

    public function test_a_recorded_death_changes_no_membership_head_flag_or_account(): void
    {
        $a = $this->activatedHead('123456789');

        app(RecordPersonDeathAction::class)->handle($a['person'], null, LifeStatusVerificationMethod::IN_PERSON, $this->admin->id);

        // Head Succession is a separate rollout gate (FU-01).
        $membership = $a['membership']->fresh();
        $this->assertTrue($membership->is_active);
        $this->assertTrue($membership->is_household_head);
        $this->assertNull($membership->ended_at);
        $this->assertSame('ACTIVE', $a['family']->fresh()->status->value);
        // The account is not deactivated by a link end.
        $this->assertTrue($a['user']->fresh()->is_active);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED->value)->count());
    }

    public function test_a_suspended_link_is_also_ended_by_a_death(): void
    {
        $a = $this->activatedHead('123456789');
        $a['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        app(RecordPersonDeathAction::class)->handle($a['person'], null, LifeStatusVerificationMethod::IN_PERSON, null);

        $this->assertSame(UserPersonLinkStatus::ENDED, $a['link']->fresh()->status);
        $this->assertNull($a['link']->fresh()->ended_by);
        $this->assertSame(AuthIdentitySupersedeReason::LINK_ENDED, $a['identity']->fresh()->supersede_reason);
    }

    public function test_a_death_without_a_link_records_no_security_event(): void
    {
        [$person] = $this->eligibleHead('123456789');

        app(RecordPersonDeathAction::class)->handle($person, null, LifeStatusVerificationMethod::IN_PERSON, $this->admin->id);

        $this->assertSame(LifeStatus::DECEASED, $person->fresh()->life_status);
        $this->assertSame([], $this->events());
    }

    public function test_a_refused_death_leaves_the_link_untouched(): void
    {
        $a = $this->activatedHead('123456789');
        $a['person']->forceFill(['birth_date' => '2000-01-01'])->save();

        try {
            // A death date before the birth date is refused by the action.
            app(RecordPersonDeathAction::class)->handle($a['person'], '1990-01-01', LifeStatusVerificationMethod::IN_PERSON, $this->admin->id);
            $this->fail('An invalid death date was accepted.');
        } catch (\Throwable) {
        }

        $this->assertSame(LifeStatus::ALIVE, $a['person']->fresh()->life_status);
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $a['identity']->fresh()->status);
        $this->assertSame([], $this->events());
    }
}
