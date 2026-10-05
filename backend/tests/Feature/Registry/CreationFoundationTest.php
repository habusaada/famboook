<?php

namespace Tests\Feature\Registry;

use App\Actions\AddFamilyMemberAction;
use App\Actions\CreateFamilyAction;
use App\Actions\CreatePersonAction;
use App\Actions\RecordPersonDeathAction;
use App\Actions\RegisterFamilyAction;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Exceptions\DuplicateNationalIdException;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\RelationshipTypes;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4B.1 shared creation foundation (docs/03 §96b): CreatePersonAction,
 * CreateFamilyAction, strict relationship types — and proof that staff
 * registration still behaves exactly as before. All data synthetic.
 */
class CreationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function person(array $data): Person
    {
        return app(CreatePersonAction::class)->handle(['full_name' => 'شخص تجريبي', 'gender' => 'MALE', ...$data], null);
    }

    private function familyData(array $data = []): array
    {
        return ['clan_code' => 'AL_BREEM', 'registration_date' => '2026-09-01', 'registration_source' => 'IMPORT', ...$data];
    }

    // ================================================== CreatePersonAction

    public function test_a_living_person_is_created_with_a_matching_code(): void
    {
        $p = $this->person(['life_status' => 'ALIVE', 'national_id' => '900000101', 'birth_date' => '1980-01-01', 'marital_status' => 'MARRIED']);

        $this->assertSame(sprintf('PER-%06d', $p->id), $p->person_code);
        $this->assertSame([LifeStatus::ALIVE, null, 'MARRIED', true], [$p->life_status, $p->death_date, $p->marital_status->value ?? $p->marital_status, $p->is_active]);
    }

    public function test_a_person_already_deceased_is_created_deceased_with_or_without_a_date(): void
    {
        $withDate = $this->person(['life_status' => 'DECEASED', 'birth_date' => '1950-01-01', 'death_date' => '2020-05-01']);
        $withoutDate = $this->person(['life_status' => 'DECEASED']);

        $this->assertSame([LifeStatus::DECEASED, '2020-05-01'], [$withDate->fresh()->life_status, $withDate->fresh()->death_date->toDateString()]);
        $this->assertSame([LifeStatus::DECEASED, null], [$withoutDate->fresh()->life_status, $withoutDate->fresh()->death_date]);
        // Created deceased: no "death recorded" lifecycle event.
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->count());
    }

    public function test_an_unknown_life_status_is_supported(): void
    {
        $this->assertSame(LifeStatus::UNKNOWN, $this->person(['life_status' => 'UNKNOWN'])->fresh()->life_status);
    }

    #[DataProvider('invalidLife')]
    public function test_invalid_life_data_is_refused_before_writing(array $data, string $field): void
    {
        try {
            $this->person($data);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
        $this->assertSame(0, Person::count());
    }

    public static function invalidLife(): array
    {
        return [
            'no life status (no default)' => [[], 'life_status'],
            'unknown life status value' => [['life_status' => 'MISSING'], 'life_status'],
            'ALIVE with a death date' => [['life_status' => 'ALIVE', 'death_date' => '2020-01-01'], 'death_date'],
            'UNKNOWN with a death date' => [['life_status' => 'UNKNOWN', 'death_date' => '2020-01-01'], 'death_date'],
            'death before birth' => [['life_status' => 'DECEASED', 'birth_date' => '1990-01-01', 'death_date' => '1980-01-01'], 'death_date'],
            'death in the future' => [['life_status' => 'DECEASED', 'death_date' => '2999-01-01'], 'death_date'],
            'malformed death date' => [['life_status' => 'DECEASED', 'death_date' => '01/02/2020'], 'death_date'],
            'invalid gender' => [['life_status' => 'ALIVE', 'gender' => 'X'], 'gender'],
            'invalid marital status' => [['life_status' => 'ALIVE', 'marital_status' => 'X'], 'marital_status'],
        ];
    }

    public function test_a_duplicate_national_id_is_refused_and_a_null_id_is_allowed(): void
    {
        $this->person(['life_status' => 'ALIVE', 'national_id' => '900000102']);
        $this->person(['life_status' => 'ALIVE', 'national_id' => null]);
        $this->person(['life_status' => 'UNKNOWN', 'national_id' => null]);

        $this->expectException(DuplicateNationalIdException::class);
        $this->person(['life_status' => 'DECEASED', 'national_id' => '900000102']);
    }

    public function test_death_rules_are_shared_with_record_person_death(): void
    {
        $person = $this->person(['life_status' => 'ALIVE', 'birth_date' => '1990-01-01']);

        try {
            app(RecordPersonDeathAction::class)->handle($person, '1980-01-01', LifeStatusVerificationMethod::IN_PERSON, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame(['تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.'], $e->errors()['death_date']);
        }
        $this->assertSame(LifeStatus::DECEASED, app(RecordPersonDeathAction::class)->handle($person, '2020-01-01', LifeStatusVerificationMethod::IN_PERSON, null)->life_status);
    }

    // ================================================== CreateFamilyAction

    public function test_a_family_is_created_with_code_clan_no_branch_and_activity(): void
    {
        $user = User::factory()->create();
        $family = app(CreateFamilyAction::class)->handle($this->familyData(['paper_form_no' => 'P-1']), $user->id);

        $this->assertSame(sprintf('FAM-%06d', $family->id), $family->family_code);
        $this->assertSame([Clan::where('code', 'AL_BREEM')->value('id'), null], [$family->clan_id, $family->branch_id]);
        $this->assertSame(['ACTIVE', 'IMPORT', '2026-09-01', 'P-1', $user->id], [
            $family->fresh()->status->value ?? $family->fresh()->status, $family->fresh()->registration_source->value ?? $family->fresh()->registration_source,
            $family->fresh()->registration_date->toDateString(), $family->paper_form_no, $family->created_by,
        ]);
        $activity = FamilyActivity::where('family_id', $family->id)->sole();
        $this->assertSame([FamilyActivityType::FAMILY_CREATED, $user->id, null], [$activity->event_type, $activity->actor_user_id, $activity->metadata]);
        // Only the Family row.
        $this->assertSame([0, 0], [Person::count(), FamilyMembership::count()]);
    }

    public function test_a_family_branch_must_belong_to_the_clan_and_be_selectable(): void
    {
        $clan = Clan::where('code', 'AL_BREEM')->firstOrFail();
        $other = Clan::create(['code' => 'SYN_OTHER', 'name' => 'عشيرة أخرى']);
        Branch::create(['clan_id' => $clan->id, 'code' => 'SYN_OK', 'name' => 'فرع']);
        Branch::create(['clan_id' => $other->id, 'code' => 'SYN_ELSEWHERE', 'name' => 'فرع آخر']);
        Branch::create(['clan_id' => $clan->id, 'code' => 'SYN_OFF', 'name' => 'فرع معطل', 'is_active' => false]);

        $this->assertNotNull(app(CreateFamilyAction::class)->handle($this->familyData(['branch_code' => 'SYN_OK']), null)->branch_id);
        foreach (['SYN_ELSEWHERE', 'SYN_OFF'] as $code) {
            try {
                app(CreateFamilyAction::class)->handle($this->familyData(['branch_code' => $code]), null);
                $this->fail("{$code} must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('branch_code', $e->errors());
            }
        }
        $this->assertSame(1, Family::count());
    }

    public function test_a_family_needs_a_known_source_and_a_date(): void
    {
        foreach ([['registration_source' => 'GUESS'], ['registration_date' => null]] as $bad) {
            try {
                app(CreateFamilyAction::class)->handle($this->familyData($bad), null);
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Family::count());
    }

    // ================================================== relationship types

    public function test_required_relationship_types_fail_loudly_when_missing_or_inactive(): void
    {
        try {
            RelationshipTypes::required(RelationshipTypes::SPOUSE);
            $this->fail('A missing type must fail.');
        } catch (MissingRelationshipTypeException $e) {
            $this->assertSame('SPOUSE', $e->typeCode);
        }

        $this->seed(RelationshipTypeSeeder::class);
        $this->assertSame(RelationshipType::where('code', 'HEAD')->value('id'), RelationshipTypes::required(RelationshipTypes::HEAD));
        $this->assertSame(RelationshipType::where('code', 'SPOUSE')->value('id'), RelationshipTypes::required(RelationshipTypes::SPOUSE));

        RelationshipType::where('code', 'SPOUSE')->update(['is_active' => false]);
        $this->expectException(MissingRelationshipTypeException::class);
        RelationshipTypes::required(RelationshipTypes::SPOUSE);
    }

    // ================================================== regressions

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        return $user;
    }

    public function test_staff_registration_still_creates_a_living_head_and_ignores_a_client_life_status(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة', 'national_id' => '900000103', 'gender' => 'MALE', 'life_status' => 'DECEASED', 'death_date' => '2020-01-01'],
            'residence' => ['city' => 'مدينة تجريبية'],
        ])->assertCreated();

        $head = Person::sole();
        $this->assertSame([LifeStatus::ALIVE, null], [$head->life_status, $head->death_date]);
        $membership = FamilyMembership::sole();
        $this->assertSame([true, RelationshipType::where('code', 'HEAD')->value('id')], [$membership->is_household_head, $membership->relationship_type_id]);
        $this->assertSame([FamilyActivityType::FAMILY_CREATED], FamilyActivity::pluck('event_type')->all());
        $this->assertSame(1, DB::table('family_residences')->count());
    }

    public function test_staff_add_member_still_creates_a_living_member_and_ignores_a_client_life_status(): void
    {
        $user = $this->staff();
        $family = app(RegisterFamilyAction::class)->handle([
            'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة', 'gender' => 'MALE'], 'residence' => [],
        ], $user->id);

        $this->actingAs($user)->postJson("/api/v1/families/{$family->family_code}/members", [
            'full_name' => 'فرد', 'gender' => 'FEMALE', 'relationship_type_id' => RelationshipType::where('code', 'SPOUSE')->value('id'),
            'life_status' => 'UNKNOWN', 'death_date' => '2020-01-01',
        ])->assertCreated();

        $member = Person::where('full_name', 'فرد')->sole();
        $this->assertSame([LifeStatus::ALIVE, null], [$member->life_status, $member->death_date]);
        $this->assertSame(FamilyActivityType::FAMILY_MEMBER_ADDED, FamilyActivity::latest('id')->value('event_type'));
    }

    private function registration(): array
    {
        return [
            'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة', 'gender' => 'MALE', 'national_id' => '900000105'], 'residence' => ['city' => 'مدينة'],
        ];
    }

    private function assertNothingRegistered(): void
    {
        $this->assertSame([0, 0, 0, 0, 0], [
            Family::withTrashed()->count(), Person::withTrashed()->count(), FamilyMembership::count(),
            DB::table('family_residences')->count(), FamilyActivity::count(),
        ]);
    }

    #[DataProvider('unusableHeadType')]
    public function test_registration_fails_explicitly_without_an_active_head_type_and_writes_nothing(bool $seed, bool $deactivate): void
    {
        if ($seed) {
            $this->seed(RelationshipTypeSeeder::class);
        }
        if ($deactivate) {
            RelationshipType::where('code', 'HEAD')->update(['is_active' => false]);
        }

        try {
            app(RegisterFamilyAction::class)->handle($this->registration(), null);
            $this->fail('Registration must fail without an active HEAD type.');
        } catch (MissingRelationshipTypeException $e) {
            $this->assertSame('HEAD', $e->typeCode);
        }
        $this->assertNothingRegistered();
    }

    public static function unusableHeadType(): array
    {
        return ['missing HEAD type' => [false, false], 'inactive HEAD type' => [true, true]];
    }

    public function test_registration_records_the_head_type_and_one_family_created_event(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        $family = app(RegisterFamilyAction::class)->handle($this->registration(), null);

        $this->assertSame(RelationshipType::where('code', 'HEAD')->value('id'), $family->householdHeadMembership->relationship_type_id);
        $this->assertSame([FamilyActivityType::FAMILY_CREATED], FamilyActivity::where('family_id', $family->id)->pluck('event_type')->all());
    }

    public function test_a_failure_in_a_later_registration_step_rolls_back_the_family_and_its_activity(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        // Fail the LAST step (residence), after the Family, FAMILY_CREATED,
        // Person and HEAD membership were written in the same transaction.
        FamilyResidence::creating(fn () => throw new RuntimeException('synthetic residence failure'));

        try {
            app(RegisterFamilyAction::class)->handle($this->registration(), null);
            $this->fail('Expected the residence failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic residence failure', $e->getMessage());
        }
        $this->assertNothingRegistered();
    }

    public function test_a_duplicate_head_id_is_refused_before_any_family_is_written(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        $this->person(['life_status' => 'ALIVE', 'national_id' => '900000104']);

        try {
            (new RegisterFamilyAction)->handle([
                'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => 'AL_BREEM',
                'household_head' => ['full_name' => 'رب أسرة', 'gender' => 'MALE', 'national_id' => '900000104'], 'residence' => [],
            ], null);
            $this->fail('Expected a duplicate National ID.');
        } catch (DuplicateNationalIdException $e) {
            $this->assertSame('household_head.national_id', $e->field);
        }
        $this->assertSame([0, 0], [Family::count(), FamilyActivity::count()]);
        $this->assertInstanceOf(AddFamilyMemberAction::class, new AddFamilyMemberAction);
    }
}
