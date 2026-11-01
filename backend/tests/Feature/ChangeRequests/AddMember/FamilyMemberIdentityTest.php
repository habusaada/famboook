<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Actions\AddFamilyMemberAction;
use App\Enums\FamilyMemberIdentityOutcome as Outcome;
use App\Enums\LifeStatus;
use App\Exceptions\DuplicateNationalIdException;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\FamilyMemberIdentity;
use App\Support\RelationshipTypes;
use Database\Seeders\RelationshipTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyNationalIds;
use Tests\TestCase;

/**
 * FamilyMemberIdentity, extracted from AddFamilyMemberHandler without changing
 * ADD_FAMILY_MEMBER (FB-FAMILY-ADD-01 WP-03). End-to-end behavior stays covered
 * by AddFamilyMemberTest, its concurrency and deadlock tests. Synthetic data only.
 */
class FamilyMemberIdentityTest extends TestCase
{
    use LegacyNationalIds;
    use RefreshDatabase;

    private const NID = '401234567';

    private function resolve(string $nationalId = self::NID)
    {
        return DB::transaction(fn () => FamilyMemberIdentity::resolve($nationalId));
    }

    public function test_an_unknown_id_is_a_new_person_and_nothing_is_created(): void
    {
        $resolution = $this->resolve();

        $this->assertSame(Outcome::NEW_PERSON, $resolution->outcome);
        $this->assertNull($resolution->person);
        $this->assertFalse($resolution->outcome->refused());
        $this->assertSame(0, Person::count());
    }

    public function test_a_living_active_person_without_a_membership_is_attachable_and_unchanged(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID, 'full_name' => 'كما في السجل']);

        $resolution = $this->resolve();

        $this->assertSame(Outcome::ATTACHABLE, $resolution->outcome);
        $this->assertTrue($person->is($resolution->person));
        $this->assertSame('كما في السجل', $person->fresh()->full_name);
        $this->assertSame(0, FamilyMembership::count());
    }

    public function test_a_single_legacy_equivalent_holder_resolves_as_stored(): void
    {
        $this->allowLegacyNationalIds();
        $person = Person::factory()->create(['national_id' => '٤٠١-٢٣٤ ٥٦٧']);

        $this->assertTrue($person->is($this->resolve()->person));
        $this->assertSame('٤٠١-٢٣٤ ٥٦٧', $person->fresh()->national_id);
    }

    public function test_duplicate_equivalent_holders_are_ambiguous(): void
    {
        $this->allowLegacyNationalIds();
        Person::factory()->create(['national_id' => '401-234-567']);
        Person::factory()->create(['national_id' => '٤٠١٢٣٤٥٦٧']);

        $resolution = $this->resolve();

        $this->assertSame(Outcome::AMBIGUOUS, $resolution->outcome);
        $this->assertNull($resolution->person);
        $this->assertTrue($resolution->outcome->refused());
    }

    public function test_an_active_membership_anywhere_is_refused_and_an_ended_one_is_not(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID]);
        $membership = FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'person_id' => $person->id, 'is_active' => true]);

        $resolution = $this->resolve();
        $this->assertSame(Outcome::ACTIVE_MEMBERSHIP, $resolution->outcome);
        $this->assertNull($resolution->person);
        $this->assertTrue($resolution->outcome->refused());

        $membership->update(['is_active' => false, 'ended_at' => now()->toDateString(), 'started_at' => now()->toDateString()]);
        $this->assertSame(Outcome::ATTACHABLE, $this->resolve()->outcome);
    }

    public function test_a_deceased_unknown_or_inactive_person_is_not_attachable(): void
    {
        foreach ([
            '411234567' => ['life_status' => LifeStatus::DECEASED],
            '412234567' => ['life_status' => LifeStatus::UNKNOWN],
            '413234567' => ['is_active' => false],
        ] as $nationalId => $attributes) {
            $person = Person::factory()->create(['national_id' => $nationalId, ...$attributes]);

            $resolution = $this->resolve($nationalId);

            $this->assertSame(Outcome::NOT_ATTACHABLE, $resolution->outcome);
            $this->assertNull($resolution->person);
            $this->assertTrue($resolution->outcome->refused());
            $this->assertFalse(FamilyMemberIdentity::attachable($person));
        }
    }

    public function test_a_soft_deleted_holder_is_not_described_and_creation_still_refuses_the_id(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        Person::factory()->create(['national_id' => self::NID])->delete();
        $family = Family::factory()->create();

        $resolution = $this->resolve();
        $this->assertSame(Outcome::NEW_PERSON, $resolution->outcome);
        $this->assertNull($resolution->person);

        try {
            app(AddFamilyMemberAction::class)->handle($family, [
                'full_name' => 'فرد تجريبي', 'national_id' => self::NID, 'gender' => 'MALE',
                'relationship_type_id' => RelationshipTypes::required('SON'),
            ], null);
            $this->fail('A soft-deleted Person keeps their National ID.');
        } catch (DuplicateNationalIdException) {
            $this->assertSame(0, FamilyMembership::where('family_id', $family->id)->count());
            $this->assertSame(0, Person::where('national_id', self::NID)->count());
        }
    }

    public function test_it_only_reads_and_locks(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID]);
        $before = $person->fresh()->toArray();

        $this->resolve();

        $this->assertSame($before, $person->fresh()->toArray());
        $this->assertSame(0, FamilyMembership::count());
    }
}
