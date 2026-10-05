<?php

namespace Tests\Feature\People;

use App\Actions\ConfirmPersonAliveAction;
use App\Actions\RecordPersonDeathAction;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Exceptions\PersonLifeStatusException;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-07 / PFP-024: ConfirmPersonAliveAction and its Staff endpoint — exactly
 * UNKNOWN → ALIVE, with the verification method recorded, and nothing else
 * touched. Synthetic data only.
 */
class ConfirmPersonAliveTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->staff = User::factory()->create()->assignRole('ADMINISTRATOR');
    }

    /** An UNKNOWN member (an imported spouse) of an ACTIVE Family with a head. */
    private function unknownMember(array $person = []): array
    {
        $family = Family::factory()->create();
        $head = Person::factory()->create();
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $head->id, 'is_household_head' => true]);
        $member = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value, 'birth_date' => null, ...$person]);
        $membership = FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $member->id]);

        return [$family, $member, $membership];
    }

    private function confirm(Person $person, LifeStatusVerificationMethod $method = LifeStatusVerificationMethod::IN_PERSON): Person
    {
        return app(ConfirmPersonAliveAction::class)->handle($person, $method, $this->staff->id);
    }

    private function confirmVia(User $as, Person $person, array $body = ['verification_method' => 'IN_PERSON']): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson('/api/v1/people/'.$person->person_code.'/confirm-alive', $body);
    }

    private function aliveActivities(): ?FamilyActivity
    {
        return FamilyActivity::where('event_type', FamilyActivityType::PERSON_ALIVE_CONFIRMED)->first();
    }

    private function refused(Person $person, string $code): void
    {
        try {
            $this->confirm($person);
            $this->fail('Expected '.$code);
        } catch (PersonLifeStatusException $e) {
            $this->assertSame($code, $e->reason);
        }
    }

    // ------------------------------------------------------------ the action

    public function test_unknown_becomes_alive_with_one_audited_activity(): void
    {
        [$family, $member] = $this->unknownMember();

        $this->confirm($member, LifeStatusVerificationMethod::STAFF_CALLBACK);

        $fresh = $member->fresh();
        $this->assertSame(LifeStatus::ALIVE, $fresh->life_status);
        $this->assertNull($fresh->death_date);
        $this->assertSame($this->staff->id, $fresh->updated_by);
        $activity = FamilyActivity::where('event_type', FamilyActivityType::PERSON_ALIVE_CONFIRMED)->sole();
        $this->assertSame($family->id, $activity->family_id);
        $this->assertSame($this->staff->id, $activity->actor_user_id);
        $this->assertSame('person', $activity->subject_type);
        $this->assertSame($member->id, $activity->subject_id);
        $this->assertSame(['verification_method' => 'STAFF_CALLBACK'], $activity->metadata);
    }

    public function test_nothing_but_the_life_status_changes(): void
    {
        $head = $this->activatedHead('123456789');
        $this->trustedMobile($head['person'], '0591234567');
        [, $member, $membership] = $this->unknownMember(['marital_status' => 'MARRIED', 'mobile' => '0598765432', 'is_active' => true]);
        $snapshot = fn () => [
            FamilyMembership::orderBy('id')->get()->map->only(['id', 'family_id', 'person_id', 'relationship_type_id', 'is_household_head', 'is_active', 'ended_at'])->all(),
            UserPersonLink::orderBy('id')->get()->map->only(['id', 'status', 'ended_at'])->all(),
            FamilyAuthIdentity::orderBy('id')->get()->map->only(['id', 'status', 'login_key'])->all(),
            PersonMobileTrust::orderBy('id')->get()->map->only(['id', 'status'])->all(),
            DB::table('sessions')->count(),
        ];
        $before = $snapshot();
        $personBefore = $member->fresh()->only(['full_name', 'gender', 'marital_status', 'birth_date', 'mobile', 'national_id', 'is_active']);

        $this->confirm($member);

        $this->assertEquals($before, $snapshot());
        $this->assertEquals($personBefore, $member->fresh()->only(['full_name', 'gender', 'marital_status', 'birth_date', 'mobile', 'national_id', 'is_active']));
        $this->assertFalse($membership->fresh()->is_household_head);
    }

    public function test_an_inactive_person_and_a_person_without_a_family_are_confirmed(): void
    {
        [, $inactive] = $this->unknownMember(['is_active' => false]);
        $loner = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value]);

        $this->confirm($inactive);
        $this->confirm($loner);

        $this->assertSame(LifeStatus::ALIVE, $inactive->fresh()->life_status);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame(LifeStatus::ALIVE, $loner->fresh()->life_status);
        // No current Family: no timeline to write to.
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_ALIVE_CONFIRMED)->count());
    }

    public function test_a_soft_deleted_person_is_not_found(): void
    {
        [, $member] = $this->unknownMember();
        $member->delete();

        $this->expectException(ModelNotFoundException::class);
        $this->confirm($member);
    }

    public function test_alive_is_refused_with_person_already_alive(): void
    {
        $alive = Person::factory()->create(['life_status' => LifeStatus::ALIVE->value]);

        $this->refused($alive, PersonLifeStatusException::PERSON_ALREADY_ALIVE);
        $this->assertNull($this->aliveActivities());
    }

    public function test_deceased_is_refused_and_never_brought_back(): void
    {
        [, $member] = $this->unknownMember();
        app(RecordPersonDeathAction::class)->handle($member, '2024-01-01', LifeStatusVerificationMethod::IN_PERSON, $this->staff->id);

        $this->refused($member, PersonLifeStatusException::PERSON_DECEASED);

        $fresh = $member->fresh();
        $this->assertSame(LifeStatus::DECEASED, $fresh->life_status);
        $this->assertSame('2024-01-01', $fresh->death_date->toDateString());
        $this->assertNull($this->aliveActivities());
    }

    public function test_unknown_with_a_death_date_is_an_inconsistent_record(): void
    {
        [, $member] = $this->unknownMember();
        // Written directly: no Domain Action produces this shape.
        DB::table('persons')->where('id', $member->id)->update(['death_date' => '2020-01-01']);

        $this->refused($member, PersonLifeStatusException::INCONSISTENT_LIFE_RECORD);

        $this->assertSame(LifeStatus::UNKNOWN, $member->fresh()->life_status);
        $this->assertNull($this->aliveActivities());
    }

    public function test_the_death_action_still_accepts_unknown_and_alive(): void
    {
        [, $unknown] = $this->unknownMember();
        $alive = Person::factory()->create(['life_status' => LifeStatus::ALIVE->value]);

        app(RecordPersonDeathAction::class)->handle($unknown, null, LifeStatusVerificationMethod::IN_PERSON, $this->staff->id);
        app(RecordPersonDeathAction::class)->handle($alive, null, LifeStatusVerificationMethod::IN_PERSON, $this->staff->id);

        $this->assertSame(LifeStatus::DECEASED, $unknown->fresh()->life_status);
        $this->assertSame(LifeStatus::DECEASED, $alive->fresh()->life_status);
    }

    public function test_the_activity_metadata_accepts_only_the_controlled_code(): void
    {
        [$family, $member] = $this->unknownMember();

        DB::transaction(function () use ($family, $member) {
            foreach ([['verification_method' => 'TOLD_BY_NEIGHBOUR'], ['verification_method' => 'IN_PERSON', 'note' => 'نص حر'], ['health_record_type' => 'DISABILITY']] as $metadata) {
                try {
                    FamilyActivityLog::record($family->id, FamilyActivityType::PERSON_ALIVE_CONFIRMED, $member, $this->staff->id, $metadata);
                    $this->fail('Expected the metadata to be refused: '.json_encode($metadata));
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
            // The code key is refused on every other event.
            $this->expectException(InvalidArgumentException::class);
            FamilyActivityLog::record($family->id, FamilyActivityType::PERSON_UPDATED, $member, $this->staff->id, ['verification_method' => 'IN_PERSON']);
        });
    }

    // ----------------------------------------------------------- household head

    public function test_an_unknown_household_head_is_confirmed_and_only_then_may_activate(): void
    {
        [$person] = $this->eligibleHead('123456789', ['life_status' => LifeStatus::UNKNOWN->value]);
        $resolver = app(FamilyAccessResolver::class);
        $this->assertNotNull($resolver->headEligibility($person->fresh()), 'An UNKNOWN head is not eligible.');

        $this->confirmVia($this->staff, $person, ['verification_method' => 'AUTHORIZED_RECORD_REVIEW'])->assertOk()
            ->assertJsonPath('data.life_status', 'ALIVE');

        $this->assertNull($resolver->headEligibility($person->fresh()), 'Now eligible for the normal activation flow.');
        // Nothing of an account was created or sent.
        $this->assertSame(0, User::whereHas('roles', fn ($q) => $q->where('name', 'FAMILY_USER'))->count());
        $this->assertSame(0, UserPersonLink::count());
        $this->assertSame(0, FamilyAuthIdentity::count());
        $this->assertSame(0, PersonMobileTrust::count());
        $this->assertSame(0, DB::table('auth_otp_challenges')->count());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertTrue($person->fresh()->activeMembership->is_household_head);
    }

    // --------------------------------------------------------------- endpoint

    public function test_authorized_staff_confirm_and_get_the_person_resource(): void
    {
        [, $member] = $this->unknownMember();

        $response = $this->confirmVia($this->staff, $member, ['verification_method' => 'STAFF_CALLBACK'])->assertOk();

        $response->assertJsonPath('data.person_code', $member->person_code)
            ->assertJsonPath('data.life_status', 'ALIVE')
            ->assertJsonPath('message', 'تم تأكيد أن الشخص على قيد الحياة');
        $this->assertSame(['verification_method' => 'STAFF_CALLBACK'], $this->aliveActivities()->metadata);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'missing method' => [[], 'verification_method'],
            'unknown method' => [['verification_method' => 'NEIGHBOUR'], 'verification_method'],
            'mobile-only SELF_OTP' => [['verification_method' => 'SELF_OTP'], 'verification_method'],
            'life status from the client' => [['verification_method' => 'IN_PERSON', 'life_status' => 'ALIVE'], 'life_status'],
            'death date' => [['verification_method' => 'IN_PERSON', 'death_date' => '2020-01-01'], 'death_date'],
            'family id' => [['verification_method' => 'IN_PERSON', 'family_id' => 1], 'family_id'],
            'person id' => [['verification_method' => 'IN_PERSON', 'person_id' => 1], 'person_id'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_refused_and_changes_nothing(array $body, string $field): void
    {
        [, $member] = $this->unknownMember();

        $this->confirmVia($this->staff, $member, $body)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(LifeStatus::UNKNOWN, $member->fresh()->life_status);
        $this->assertNull($this->aliveActivities());
    }

    public function test_conflicts_answer_409_with_their_code(): void
    {
        $alive = Person::factory()->create(['life_status' => LifeStatus::ALIVE->value]);
        $deceased = Person::factory()->create(['life_status' => LifeStatus::DECEASED->value]);

        $this->confirmVia($this->staff, $alive)->assertStatus(409)->assertJsonPath('code', 'PERSON_ALREADY_ALIVE');
        $this->confirmVia($this->staff, $deceased)->assertStatus(409)->assertJsonPath('code', 'PERSON_DECEASED');
        $this->assertSame(LifeStatus::DECEASED, $deceased->fresh()->life_status);
    }

    public function test_a_soft_deleted_person_answers_404(): void
    {
        [, $member] = $this->unknownMember();
        $member->delete();

        $this->confirmVia($this->staff, $member)->assertNotFound();
    }

    public function test_staff_without_person_record_death_are_refused(): void
    {
        [, $member] = $this->unknownMember();
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->confirmVia(User::factory()->create()->assignRole($role), $member)->assertForbidden();
        }

        $this->assertSame(LifeStatus::UNKNOWN, $member->fresh()->life_status);
    }

    public function test_family_side_coordinator_and_mixed_accounts_never_reach_it(): void
    {
        [, $member] = $this->unknownMember();
        $familyUser = $this->activatedHead('111111111')['user'];
        $coordinator = $this->coordinator('222222222');
        $mixed = $this->activatedHead('333333333')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        foreach ([$familyUser, $coordinator, $mixed] as $user) {
            // Even holding the permission directly.
            $user->givePermissionTo('person.record-death');
            $this->confirmVia($user, $member)->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/people/'.$member->person_code.'/confirm-alive', ['verification_method' => 'IN_PERSON'])->assertUnauthorized();

        $this->assertSame(LifeStatus::UNKNOWN, $member->fresh()->life_status);
    }

    public function test_the_generic_person_update_never_writes_life_status_or_death_date(): void
    {
        [, $member] = $this->unknownMember();
        $editor = User::factory()->create()->assignRole('ADMINISTRATOR');
        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($editor)->patchJson('/api/v1/people/'.$member->person_code, [
            'full_name' => 'اسم معدّل', 'life_status' => 'ALIVE', 'death_date' => '2020-01-01',
        ])->assertOk();

        $this->assertSame('اسم معدّل', $member->fresh()->full_name);
        $this->assertSame(LifeStatus::UNKNOWN, $member->fresh()->life_status);
        $this->assertNull($member->fresh()->death_date);
        $this->assertSame('UNKNOWN', $response->json('data.life_status'));
    }
}
