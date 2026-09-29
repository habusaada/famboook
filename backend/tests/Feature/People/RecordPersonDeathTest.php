<?php

namespace Tests\Feature\People;

use App\Actions\RecordPersonDeathAction;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Registry\BuildsRegistryFixtures;
use Tests\TestCase;

/**
 * RecordPersonDeathAction (docs/03 §28-§30): the only write path to
 * DECEASED. The death date is optional and never invented; nothing but the
 * Person's life status/death date changes — no spouse becomes WIDOWED or
 * head, no membership is touched.
 */
class RecordPersonDeathTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private Family $family;

    private Person $head;

    private Person $spouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();

        // Head born 1980-01-15 (fixture default).
        $codes = $this->family('رب أسرة للوفاة');
        $spouseCode = $this->addMember($codes['family_code'], [
            'full_name' => 'زوجة تجريبية',
            'gender' => 'FEMALE',
            'birth_date' => '1985-03-03',
            'marital_status' => 'MARRIED',
            'relationship_type_id' => RelationshipType::where('code', 'SPOUSE')->value('id'),
        ])->assertCreated()->json('data.person_code');

        $this->family = Family::where('family_code', $codes['family_code'])->sole();
        $this->head = Person::where('person_code', $codes['person_code'])->sole();
        $this->spouse = Person::where('person_code', $spouseCode)->sole();
    }

    private function record(Person $person, ?string $date): Person
    {
        return app(RecordPersonDeathAction::class)->handle($person, $date, $this->staff->id);
    }

    private function deathActivities(): Collection
    {
        return FamilyActivity::where('family_id', $this->family->id)
            ->where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->get();
    }

    public function test_records_death_without_a_date(): void
    {
        $person = $this->record($this->head, null);

        $this->assertSame(LifeStatus::DECEASED, $person->life_status);
        $this->assertNull($person->death_date);
        $this->assertSame(LifeStatus::DECEASED, $this->head->fresh()->life_status);
        $this->assertNull($this->head->fresh()->death_date);
    }

    public function test_records_death_with_a_valid_date(): void
    {
        $this->record($this->head, '2024-11-20');

        $head = $this->head->fresh();
        $this->assertSame(LifeStatus::DECEASED, $head->life_status);
        $this->assertSame('2024-11-20', $head->death_date->toDateString());
        $this->assertSame($this->staff->id, $head->updated_by);
    }

    public function test_death_on_the_birth_date_and_today_are_accepted(): void
    {
        $this->record($this->head, '1980-01-15');
        $this->assertSame('1980-01-15', $this->head->fresh()->death_date->toDateString());

        $this->record($this->spouse, now()->toDateString());
        $this->assertSame(now()->toDateString(), $this->spouse->fresh()->death_date->toDateString());
    }

    /** @return array<string, array{string}> */
    public static function invalidDates(): array
    {
        return [
            'future' => [now()->addDay()->toDateString()],
            'before birth date' => ['1980-01-14'],
            'not a calendar date' => ['2020-02-30'],
            'wrong format' => ['15/01/2020'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_rejects_an_invalid_death_date_and_changes_nothing(string $date): void
    {
        try {
            $this->record($this->head, $date);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('death_date', $e->errors());
        }

        $head = $this->head->fresh();
        $this->assertSame(LifeStatus::ALIVE, $head->life_status);
        $this->assertNull($head->death_date);
        $this->assertCount(0, $this->deathActivities());
    }

    public function test_unknown_birth_date_does_not_block_a_past_death_date(): void
    {
        $this->head->forceFill(['birth_date' => null])->save();

        $this->record($this->head, '1950-06-01');

        $this->assertSame('1950-06-01', $this->head->fresh()->death_date->toDateString());
    }

    public function test_an_already_deceased_person_is_refused(): void
    {
        $this->record($this->head, '2024-11-20');

        try {
            $this->record($this->head, '2023-01-01');
            $this->fail('Expected 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('2024-11-20', $this->head->fresh()->death_date->toDateString());
        $this->assertCount(1, $this->deathActivities());
    }

    public function test_records_one_activity_without_metadata(): void
    {
        $this->record($this->spouse, '2024-11-20');

        $activity = $this->deathActivities()->sole();
        $this->assertSame($this->staff->id, $activity->actor_user_id);
        $this->assertSame('person', $activity->subject_type);
        $this->assertSame($this->spouse->id, $activity->subject_id);
        $this->assertNull($activity->metadata);
        $this->assertStringNotContainsString('2024-11-20', json_encode($activity->getAttributes()));
    }

    public function test_person_without_a_current_family_records_no_activity(): void
    {
        $loner = Person::factory()->create(['birth_date' => '1990-01-01']);

        $this->record($loner, null);

        $this->assertSame(LifeStatus::DECEASED, $loner->fresh()->life_status);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->count());
    }

    public function test_head_death_changes_no_spouse_membership_or_head_state(): void
    {
        $membershipsBefore = FamilyMembership::orderBy('id')->get()->map->only([
            'id', 'family_id', 'person_id', 'relationship_type_id', 'is_household_head', 'is_active', 'ended_at', 'end_reason',
        ]);

        $this->record($this->head, '2024-11-20');

        $membershipsAfter = FamilyMembership::orderBy('id')->get()->map->only([
            'id', 'family_id', 'person_id', 'relationship_type_id', 'is_household_head', 'is_active', 'ended_at', 'end_reason',
        ]);
        $this->assertEquals($membershipsBefore, $membershipsAfter);

        // The deceased head stays the (active) head: review, not auto-promotion (docs/03 §16).
        $this->assertSame($this->head->id, $this->family->fresh()->householdHeadMembership->person_id);

        // The spouse is not made WIDOWED, head or anything else.
        $spouse = $this->spouse->fresh();
        $this->assertSame(MaritalStatus::MARRIED, $spouse->marital_status);
        $this->assertSame(LifeStatus::ALIVE, $spouse->life_status);
        $this->assertFalse($spouse->activeMembership->is_household_head);

        // Life status is independent of record status (docs/03 §27).
        $this->assertTrue($this->head->fresh()->is_active);
    }
}
