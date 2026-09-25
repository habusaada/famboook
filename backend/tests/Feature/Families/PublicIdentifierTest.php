<?php

namespace Tests\Feature\Families;

use App\Actions\AddFamilyMemberAction;
use App\Actions\RegisterFamilyAction;
use App\Models\Family;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Support\BusinessIdentifier;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FAM-/PER- public identifiers (docs/02 §71, docs/04 §7). The code is
 * formatted from the id reserved by BusinessIdentifier::nextId(), and that
 * same id must be the row's primary key — the reserved id used to be
 * dropped by create() (id is not fillable), so the insert drew a second
 * sequence value and codes skipped.
 *
 * On SQLite nextId() is max(id)+1, which normally equals the next
 * autoincrement value, hiding the bug. The regression tests below make
 * the autoincrement sequence run ahead of max(id) (as a PostgreSQL
 * sequence does after the extra nextval) so a dropped id is detectable.
 */
class PublicIdentifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
    }

    private function register(string $name = 'رب أسرة تجريبي'): Family
    {
        return app(RegisterFamilyAction::class)->handle([
            'registration_date' => '2026-09-20',
            'registration_source' => 'MANUAL_ENTRY',
            'household_head' => ['full_name' => $name, 'gender' => 'MALE', 'birth_date' => '1980-01-01'],
            'clan_code' => 'AL_BREEM',
            'residence' => ['governorate' => 'محافظة تجريبية', 'city' => 'مدينة تجريبية'],
        ], null);
    }

    private function addMember(Family $family, string $name = 'فرد تجريبي'): Person
    {
        return app(AddFamilyMemberAction::class)->handle($family, [
            'full_name' => $name,
            'gender' => 'FEMALE',
            'birth_date' => '2005-01-01',
            'relationship_type_id' => RelationshipType::where('code', 'DAUGHTER')->value('id'),
        ], null)->person;
    }

    private function headOf(Family $family): Person
    {
        return $family->householdHeadMembership()->first()->person;
    }

    /** Pushes the table's autoincrement sequence past max(id) (SQLite). */
    private function advanceSequence(string $table, int $by): void
    {
        DB::table('sqlite_sequence')->updateOrInsert(
            ['name' => $table],
            ['seq' => (int) DB::table($table)->max('id') + $by]
        );
    }

    public function test_code_format_is_unchanged(): void
    {
        $this->assertSame('FAM-000001', BusinessIdentifier::format('FAM', 1));
        $this->assertSame('PER-000042', BusinessIdentifier::format('PER', 42));
        $this->assertSame('FAM-123456', BusinessIdentifier::format('FAM', 123456));
    }

    public function test_family_registration_code_matches_the_row_id(): void
    {
        $family = $this->register();
        $head = $this->headOf($family);

        $this->assertMatchesRegularExpression('/^FAM-\d{6}$/', $family->family_code);
        $this->assertSame(BusinessIdentifier::format('FAM', $family->id), $family->family_code);
        $this->assertSame(BusinessIdentifier::format('PER', $head->id), $head->person_code);
        // The stored row agrees with the returned model.
        $this->assertSame($family->family_code, Family::find($family->id)->family_code);
    }

    public function test_adding_a_member_code_matches_the_row_id(): void
    {
        $person = $this->addMember($this->register());

        $this->assertMatchesRegularExpression('/^PER-\d{6}$/', $person->person_code);
        $this->assertSame(BusinessIdentifier::format('PER', $person->id), $person->person_code);
        $this->assertSame($person->person_code, Person::find($person->id)->person_code);
    }

    public function test_reserved_id_is_kept_when_the_sequence_runs_ahead(): void
    {
        // Regression: with create() the reserved id was dropped and the row
        // received the next sequence value instead, so code and id diverged.
        $this->register();
        $this->advanceSequence('families', 5);
        $this->advanceSequence('persons', 5);

        $family = $this->register('رب أسرة ثانية');
        $member = $this->addMember($family);

        $this->assertSame(BusinessIdentifier::format('FAM', $family->id), $family->family_code);
        $this->assertSame(BusinessIdentifier::format('PER', $this->headOf($family)->id), $this->headOf($family)->person_code);
        $this->assertSame(BusinessIdentifier::format('PER', $member->id), $member->person_code);
    }

    public function test_consecutive_records_do_not_skip_codes(): void
    {
        $a = $this->register('أ');
        $b = $this->register('ب');
        $memberA = $this->addMember($a, 'فرد أ');
        $memberB = $this->addMember($b, 'فرد ب');

        $this->assertSame($a->id + 1, $b->id);
        $this->assertSame(BusinessIdentifier::format('FAM', $a->id + 1), $b->family_code);
        // head A, head B, member A, member B: one id each, no gaps.
        $this->assertSame(
            [$this->headOf($a)->id, $this->headOf($a)->id + 1, $this->headOf($a)->id + 2, $this->headOf($a)->id + 3],
            [$this->headOf($a)->id, $this->headOf($b)->id, $memberA->id, $memberB->id]
        );
    }

    public function test_codes_are_unique(): void
    {
        $families = collect(range(1, 5))->map(fn ($i) => $this->register("رب أسرة {$i}"));
        foreach ($families as $family) {
            $this->addMember($family);
            $this->addMember($family);
        }

        $this->assertSame(5, Family::distinct()->count('family_code'));
        $this->assertSame(15, Person::distinct()->count('person_code'));
        $this->assertSame(Person::count(), Person::distinct()->count('person_code'));
    }

    public function test_existing_identifiers_and_historical_gaps_are_untouched(): void
    {
        // A pre-fix record whose code does not match its id (historical gap).
        $legacy = Family::factory()->create(['family_code' => 'FAM-000007']);
        $legacyId = $legacy->id;

        $new = $this->register();

        $this->assertSame('FAM-000007', $legacy->fresh()->family_code);
        $this->assertSame($legacyId, $legacy->fresh()->id);
        $this->assertSame(BusinessIdentifier::format('FAM', $new->id), $new->family_code);
        $this->assertNotSame($legacy->family_code, $new->family_code);
    }

    public function test_family_and_person_apis_still_use_public_codes(): void
    {
        $family = $this->register();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('SUPER_ADMIN');

        $this->actingAs($admin)->getJson("/api/v1/families/{$family->family_code}")
            ->assertOk()
            ->assertJsonPath('data.family_code', $family->family_code);
        $this->actingAs($admin)->getJson('/api/v1/people/'.$this->headOf($family)->person_code)
            ->assertOk()
            ->assertJsonPath('data.person_code', $this->headOf($family)->person_code);
    }
}
