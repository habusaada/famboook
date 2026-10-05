<?php

namespace Tests\Feature\People;

use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\FamilyAccessDenial;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-10: the Staff death endpoint POST /api/v1/people/{person}/record-death
 * (RecordPersonDeathAction, person.record-death). ALIVE or UNKNOWN →
 * DECEASED with an explicit death date (a date or null) and a required
 * verification method; irreversible; a household head stays head with no
 * successor (FU-01). Synthetic data only.
 */
class RecordPersonDeathEndpointTest extends TestCase
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

    /** A non-head member (born 1990-01-01) of an ACTIVE Family with an ALIVE head. */
    private function member(array $person = []): array
    {
        $family = Family::factory()->create();
        $head = Person::factory()->create();
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $head->id, 'is_household_head' => true]);
        $member = Person::factory()->create(['birth_date' => '1990-01-01', ...$person]);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $member->id]);

        return [$family, $member, $head];
    }

    private function recordVia(User $as, Person $person, array $body = ['death_date' => '2024-11-20', 'verification_method' => 'IN_PERSON']): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson('/api/v1/people/'.$person->person_code.'/record-death', $body);
    }

    private function deathActivity(): ?FamilyActivity
    {
        return FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->first();
    }

    private function assertUnchanged(Person $person, LifeStatus $life = LifeStatus::ALIVE): void
    {
        $fresh = $person->fresh();
        $this->assertSame($life, $fresh->life_status);
        $this->assertNull($fresh->death_date);
        $this->assertNull($this->deathActivity());
    }

    // ------------------------------------------------------------ transitions

    public function test_alive_becomes_deceased_with_a_known_date(): void
    {
        [$family, $member] = $this->member();

        $response = $this->recordVia($this->staff, $member, ['death_date' => '2024-11-20', 'verification_method' => 'STAFF_CALLBACK'])->assertOk();

        $response->assertJsonPath('data.person_code', $member->person_code)
            ->assertJsonPath('data.life_status', 'DECEASED')
            ->assertJsonPath('data.family_membership.family_code', $family->family_code)
            ->assertJsonPath('message', 'تم تسجيل الوفاة');
        $fresh = $member->fresh();
        $this->assertSame(LifeStatus::DECEASED, $fresh->life_status);
        $this->assertSame('2024-11-20', $fresh->death_date->toDateString());
        $this->assertSame($this->staff->id, $fresh->updated_by);
    }

    public function test_unknown_becomes_deceased_with_an_explicitly_unknown_date(): void
    {
        [, $member] = $this->member(['life_status' => LifeStatus::UNKNOWN->value]);

        $this->recordVia($this->staff, $member, ['death_date' => null, 'verification_method' => 'IN_PERSON'])
            ->assertOk()->assertJsonPath('data.life_status', 'DECEASED');

        $this->assertSame(LifeStatus::DECEASED, $member->fresh()->life_status);
        $this->assertNull($member->fresh()->death_date);
    }

    public function test_one_activity_with_the_controlled_method_and_the_actor(): void
    {
        [$family, $member] = $this->member();

        $this->recordVia($this->staff, $member, ['death_date' => '2024-11-20', 'verification_method' => 'AUTHORIZED_RECORD_REVIEW'])->assertOk();

        $activity = FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->sole();
        $this->assertSame($family->id, $activity->family_id);
        $this->assertSame($this->staff->id, $activity->actor_user_id);
        $this->assertSame($member->id, $activity->subject_id);
        $this->assertSame(['verification_method' => 'AUTHORIZED_RECORD_REVIEW'], $activity->metadata);
    }

    public function test_an_already_deceased_person_answers_409_and_nothing_changes(): void
    {
        [, $member] = $this->member();
        $this->recordVia($this->staff, $member)->assertOk();

        $this->recordVia($this->staff, $member, ['death_date' => null, 'verification_method' => 'IN_PERSON'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'وفاة هذا الشخص مسجّلة مسبقًا.', 'code' => 'PERSON_ALREADY_DECEASED']);

        $this->assertSame('2024-11-20', $member->fresh()->death_date->toDateString());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->count());
    }

    public function test_a_recorded_death_is_never_undone(): void
    {
        [, $member] = $this->member();
        $this->recordVia($this->staff, $member)->assertOk();

        // Confirm-alive refuses a DECEASED Person; the generic update ignores life status.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)->postJson('/api/v1/people/'.$member->person_code.'/confirm-alive', ['verification_method' => 'IN_PERSON'])
            ->assertStatus(409)->assertJsonPath('code', 'PERSON_DECEASED');
        $this->actingAs($this->staff)->patchJson('/api/v1/people/'.$member->person_code, ['life_status' => 'ALIVE', 'death_date' => null])
            ->assertOk()->assertJsonPath('data.life_status', 'DECEASED');

        $this->assertSame(LifeStatus::DECEASED, $member->fresh()->life_status);
        $this->assertSame('2024-11-20', $member->fresh()->death_date->toDateString());
    }

    // --------------------------------------------------------------- input

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'omitted death date' => [['verification_method' => 'IN_PERSON'], 'death_date'],
            'future death date' => [['death_date' => now()->addDay()->toDateString(), 'verification_method' => 'IN_PERSON'], 'death_date'],
            'before the birth date' => [['death_date' => '1989-12-31', 'verification_method' => 'IN_PERSON'], 'death_date'],
            'not a calendar date' => [['death_date' => '2020-02-30', 'verification_method' => 'IN_PERSON'], 'death_date'],
            'wrong format' => [['death_date' => '20/11/2024', 'verification_method' => 'IN_PERSON'], 'death_date'],
            'missing method' => [['death_date' => null], 'verification_method'],
            'unknown method' => [['death_date' => null, 'verification_method' => 'NEIGHBOUR'], 'verification_method'],
            'mobile-only SELF_OTP' => [['death_date' => null, 'verification_method' => 'SELF_OTP'], 'verification_method'],
            'life status from the client' => [['death_date' => null, 'verification_method' => 'IN_PERSON', 'life_status' => 'DECEASED'], 'life_status'],
            'family id' => [['death_date' => null, 'verification_method' => 'IN_PERSON', 'family_id' => 1], 'family_id'],
            'person id' => [['death_date' => null, 'verification_method' => 'IN_PERSON', 'person_id' => 1], 'person_id'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_refused_and_changes_nothing(array $body, string $field): void
    {
        [, $member] = $this->member();

        $this->recordVia($this->staff, $member, $body)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertUnchanged($member);
    }

    public function test_a_soft_deleted_or_missing_person_answers_404(): void
    {
        [, $member] = $this->member();
        $member->delete();

        $this->recordVia($this->staff, $member)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)->postJson('/api/v1/people/PER-999999/record-death', ['death_date' => null, 'verification_method' => 'IN_PERSON'])
            ->assertNotFound();
    }

    // ------------------------------------------------- Family Portal effects

    public function test_a_linked_person_loses_link_identity_and_sessions(): void
    {
        // Session rows exist only with the database driver.
        config(['session.driver' => 'database']);
        $a = $this->activatedHead('123456789');
        $this->sessionRowFor($a['user']);

        $this->recordVia($this->staff, $a['person'])->assertOk();

        $this->assertSame(UserPersonLinkStatus::ENDED, $a['link']->fresh()->status);
        $this->assertSame('PERSON_DECEASED', $a['link']->fresh()->end_reason);
        $this->assertSame($this->staff->id, $a['link']->fresh()->ended_by);
        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $a['identity']->fresh()->status);
        $this->assertSame(AuthIdentitySupersedeReason::LINK_ENDED, $a['identity']->fresh()->supersede_reason);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        // The account itself is not deactivated.
        $this->assertTrue($a['user']->fresh()->is_active);
    }

    public function test_a_household_head_death_keeps_the_head_and_assigns_no_successor(): void
    {
        $a = $this->activatedHead('123456789');
        $spouse = Person::factory()->create(['national_id' => '987654321']);
        $spouseMembership = FamilyMembership::factory()->create(['family_id' => $a['family']->id, 'person_id' => $spouse->id]);
        $memberships = fn () => FamilyMembership::orderBy('id')->get()->map->only(['id', 'family_id', 'person_id', 'relationship_type_id', 'is_household_head', 'is_active', 'ended_at'])->all();
        $before = $memberships();

        $this->recordVia($this->staff, $a['person'], ['death_date' => null, 'verification_method' => 'IN_PERSON'])
            ->assertOk()->assertJsonPath('data.family_membership.is_household_head', true);

        // Memberships and the head flag are untouched; the deceased stays head.
        $this->assertEquals($before, $memberships());
        $this->assertSame($a['person']->id, $a['family']->fresh()->householdHeadMembership->person_id);
        $this->assertFalse($spouseMembership->fresh()->is_household_head);
        $this->assertSame(1, FamilyMembership::where('family_id', $a['family']->id)->where('is_household_head', true)->count());
        $this->assertSame('ACTIVE', $a['family']->fresh()->status->value);

        // The household temporarily has no eligible Family Portal user.
        $resolver = app(FamilyAccessResolver::class);
        $this->assertSame(FamilyAccessDenial::NO_LINK, $resolver->familyContext($a['user']->fresh())->denial);
        $this->assertSame(FamilyAccessDenial::PERSON_NOT_ALIVE, $resolver->headEligibility($a['person']->fresh()));
        $this->assertSame(FamilyAccessDenial::NOT_HOUSEHOLD_HEAD, $resolver->headEligibility($spouse->fresh()));
        $this->app['auth']->forgetGuards();
        $this->actingAs($a['user']->fresh())->getJson('/api/v1/family/household')->assertForbidden();
    }

    // ------------------------------------------------------- authorization

    public function test_staff_without_person_record_death_are_refused(): void
    {
        [, $member] = $this->member();
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->recordVia(User::factory()->create()->assignRole($role), $member)->assertForbidden();
        }

        $this->assertUnchanged($member);
    }

    public function test_super_admin_may_record(): void
    {
        [, $member] = $this->member();

        $this->recordVia(User::factory()->create()->assignRole('SUPER_ADMIN'), $member)->assertOk();
    }

    public function test_family_side_coordinator_and_mixed_accounts_never_reach_it(): void
    {
        [, $member] = $this->member();
        $familyUser = $this->activatedHead('111111111')['user'];
        $coordinator = $this->coordinator('222222222');
        $mixed = $this->activatedHead('333333333')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        foreach ([$familyUser, $coordinator, $mixed] as $user) {
            // Even holding the permission directly.
            $user->givePermissionTo('person.record-death');
            $this->recordVia($user, $member)->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/people/'.$member->person_code.'/record-death', ['death_date' => null, 'verification_method' => 'IN_PERSON'])
            ->assertUnauthorized();

        $this->assertUnchanged($member);
    }

    public function test_the_generic_person_update_never_writes_life_status_or_death_date(): void
    {
        [, $member] = $this->member();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->staff)->patchJson('/api/v1/people/'.$member->person_code, [
            'full_name' => 'اسم معدّل', 'life_status' => 'DECEASED', 'death_date' => '2020-01-01',
        ])->assertOk()->assertJsonPath('data.life_status', 'ALIVE');

        $this->assertSame('اسم معدّل', $member->fresh()->full_name);
        $this->assertUnchanged($member);
    }
}
