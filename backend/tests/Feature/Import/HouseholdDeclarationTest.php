<?php

namespace Tests\Feature\Import;

use App\Actions\RecordHouseholdDeclarationAction;
use App\Enums\FamilyActivityType;
use App\Enums\RegistrationSource;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Registry\BuildsRegistryFixtures;
use Tests\TestCase;

/**
 * Declared Household Statistics (docs/02 §20a, docs/03 §55c): dated
 * source declarations, one current per Family, history kept, never Persons
 * and never the derived Registered Household Size.
 */
class HouseholdDeclarationTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private Family $family;

    private string $familyCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();

        $this->familyCode = $this->family('رب أسرة للإعلان')['family_code'];
        $this->family = Family::where('family_code', $this->familyCode)->sole();
    }

    /** @param array<string, mixed> $data */
    private function declare(array $data = []): FamilyHouseholdDeclaration
    {
        return app(RecordHouseholdDeclarationAction::class)->handle($this->family, [
            'declared_household_size' => 7,
            'declared_living_sons' => 3,
            'declared_living_daughters' => 2,
            'source' => 'IMPORT',
            ...$data,
        ], $this->staff->id);
    }

    public function test_creates_a_current_declaration(): void
    {
        $declaration = $this->declare(['declared_at' => '2026-09-01', 'notes' => 'ملاحظة تجريبية']);

        $declaration->refresh();
        $this->assertSame(7, $declaration->declared_household_size);
        $this->assertSame(3, $declaration->declared_living_sons);
        $this->assertSame(2, $declaration->declared_living_daughters);
        $this->assertSame('2026-09-01', $declaration->declared_at->toDateString());
        $this->assertSame(RegistrationSource::IMPORT, $declaration->source);
        $this->assertTrue($declaration->is_current);
        $this->assertSame($this->staff->id, $declaration->created_by);
        $this->assertTrue($declaration->family->is($this->family));
    }

    public function test_family_relationships_expose_current_and_history(): void
    {
        $first = $this->declare(['declared_household_size' => 5]);
        $second = $this->declare(['declared_household_size' => 6]);

        $family = $this->family->fresh();
        $this->assertTrue($family->currentHouseholdDeclaration->is($second));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $family->householdDeclarations->pluck('id')->all());
        $this->assertSame([$second->id], FamilyHouseholdDeclaration::current()->pluck('id')->all());
    }

    public function test_a_new_declaration_keeps_the_previous_one_as_history(): void
    {
        $first = $this->declare(['declared_household_size' => 5, 'declared_living_sons' => 2]);
        $this->declare(['declared_household_size' => 8, 'declared_living_sons' => 4]);

        $first->refresh();
        $this->assertFalse($first->is_current);
        // History is never rewritten.
        $this->assertSame(5, $first->declared_household_size);
        $this->assertSame(2, $first->declared_living_sons);
        $this->assertSame(2, FamilyHouseholdDeclaration::where('family_id', $this->family->id)->count());
        $this->assertSame(1, FamilyHouseholdDeclaration::where('family_id', $this->family->id)->where('is_current', true)->count());
    }

    public function test_the_database_allows_only_one_current_declaration_per_family(): void
    {
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $this->family->id, 'is_current' => true]);
        // Historical rows are unlimited.
        FamilyHouseholdDeclaration::factory()->count(2)->create(['family_id' => $this->family->id, 'is_current' => false]);

        $this->expectException(QueryException::class);
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $this->family->id, 'is_current' => true]);
    }

    public function test_current_declarations_of_different_families_are_independent(): void
    {
        $other = Family::where('family_code', $this->family('رب أسرة أخرى')['family_code'])->sole();

        $this->declare();
        app(RecordHouseholdDeclarationAction::class)->handle($other, ['declared_household_size' => 3, 'source' => 'IMPORT'], null);

        $this->assertSame(2, FamilyHouseholdDeclaration::current()->count());
        $this->assertTrue($this->family->fresh()->currentHouseholdDeclaration->is_current);
    }

    public function test_zero_and_partially_declared_values_are_accepted_as_declared(): void
    {
        $declaration = $this->declare(['declared_household_size' => 0, 'declared_living_sons' => null, 'declared_living_daughters' => null]);

        $this->assertSame(0, $declaration->fresh()->declared_household_size);
        $this->assertNull($declaration->fresh()->declared_living_sons);
    }

    public function test_inconsistent_declared_counts_are_not_refused_here(): void
    {
        // Consistency (sons + daughters vs size) is a Phase 2 review flag.
        $declaration = $this->declare(['declared_household_size' => 2, 'declared_living_sons' => 5, 'declared_living_daughters' => 4]);

        $this->assertTrue($declaration->exists);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidDeclarations(): array
    {
        return [
            'negative size' => [['declared_household_size' => -1], 'declared_household_size'],
            'negative sons' => [['declared_living_sons' => -2], 'declared_living_sons'],
            'negative daughters' => [['declared_living_daughters' => -3], 'declared_living_daughters'],
            'non-integer size' => [['declared_household_size' => 'سبعة'], 'declared_household_size'],
            'nothing declared' => [['declared_household_size' => null, 'declared_living_sons' => null, 'declared_living_daughters' => null], 'declared_household_size'],
            'future declared_at' => [['declared_at' => now()->addDay()->toDateString()], 'declared_at'],
            'unknown source' => [['source' => 'EXCEL'], 'source'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidDeclarations')]
    public function test_rejects_invalid_declarations_and_writes_nothing(array $data, string $field): void
    {
        $this->declare(['declared_household_size' => 4]);

        try {
            $this->declare($data);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        // The existing current declaration is untouched.
        $this->assertSame(1, FamilyHouseholdDeclaration::count());
        $this->assertSame(4, $this->family->fresh()->currentHouseholdDeclaration->declared_household_size);
    }

    public function test_declared_size_never_creates_persons_or_changes_the_registered_size(): void
    {
        $persons = Person::count();
        $memberships = FamilyMembership::count();

        $this->declare(['declared_household_size' => 7, 'declared_living_sons' => 3, 'declared_living_daughters' => 3]);

        $this->assertSame($persons, Person::count());
        $this->assertSame($memberships, FamilyMembership::count());

        // Registered Household Size stays derived from active memberships.
        $this->actingAs($this->staff)->getJson("/api/v1/families/{$this->familyCode}")
            ->assertOk()
            ->assertJsonPath('data.member_count', 1);
    }

    public function test_records_one_activity_without_the_declared_values(): void
    {
        $this->declare();

        $activity = FamilyActivity::where('family_id', $this->family->id)
            ->where('event_type', FamilyActivityType::HOUSEHOLD_DECLARATION_RECORDED)->sole();
        $this->assertSame($this->staff->id, $activity->actor_user_id);
        $this->assertSame('family', $activity->subject_type);
        $this->assertNull($activity->metadata);
    }
}
