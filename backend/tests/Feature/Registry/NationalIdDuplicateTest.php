<?php

namespace Tests\Feature\Registry;

use App\Exceptions\DuplicateNationalIdException;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\RegistryNationalId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * National ID duplicate prevention (docs/03 §21, AUTH-ADR-058), as amended by
 * CO-01D.4: every new Person needs a valid nine-digit National ID, stored in
 * canonical form, so an equivalently formatted value is the same value. The
 * creation actions enforce it, never echo the National ID, never merge or
 * attach the existing Person.
 */
class NationalIdDuplicateTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private const NID = '913570246';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    /**
     * The 422 names the field (national_id) in the usual validation shape,
     * but never contains the value, and the references carry only codes,
     * the name and family context.
     */
    private function assertDuplicatePayloadIsSafe(TestResponse $response): void
    {
        $this->assertStringNotContainsString(self::NID, $response->getContent());
        foreach ($response->json('duplicate.matches') as $match) {
            $this->assertSame(['person_code', 'full_name', 'family'], array_keys($match));
            $this->assertSame(['family_code', 'is_household_head', 'relationship'], array_keys($match['family']));
        }
    }

    public function test_new_ids_are_accepted_and_missing_or_invalid_ids_are_refused(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);
        $before = Person::count();

        $this->register(['full_name' => 'رب جديد', 'national_id' => '913570247'])->assertCreated();
        $this->addMember($existing['family_code'], ['national_id' => '913570248'])->assertCreated();
        // Arabic-Indic digits and separators are normalized and stored canonically.
        $this->addMember($existing['family_code'], ['full_name' => 'فرد بأرقام عربية', 'national_id' => '٩١٣-٥٧٠ ٢٤٩'])->assertCreated();
        $this->assertSame('913570249', Person::where('full_name', 'فرد بأرقام عربية')->value('national_id'));

        // CO-01D.4: a missing or invalid National ID is refused, newborns included.
        foreach ([null, '', 'SYN-NEW-0001', '91357024', '9135702400', '000000000'] as $invalid) {
            $this->register(['full_name' => 'رب بلا هوية صالحة', 'national_id' => $invalid])
                ->assertStatus(422)->assertJsonPath('errors', ['household_head.national_id' => [RegistryNationalId::MESSAGE]]);
            $this->addMember($existing['family_code'], ['full_name' => 'مولود جديد', 'birth_date' => now()->toDateString(), 'national_id' => $invalid])
                ->assertStatus(422)->assertJsonPath('errors.national_id', [RegistryNationalId::MESSAGE]);
        }
        $this->assertSame($before + 3, Person::count());
        $this->assertSame(0, Person::whereNull('national_id')->count());
    }

    public function test_an_equivalent_formatting_is_the_same_national_id(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);

        $response = $this->addMember($existing['family_code'], ['full_name' => 'نفس الهوية بتنسيق آخر', 'national_id' => '٩١٣ ٥٧٠ ٢٤٦']);

        $response->assertStatus(422)->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]]);
        $this->assertDuplicatePayloadIsSafe($response);
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
    }

    public function test_a_soft_deleted_person_keeps_their_national_id_without_being_described(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);
        Person::where('person_code', $existing['person_code'])->sole()->delete();

        $response = $this->register(['full_name' => 'محاولة على هوية محذوفة', 'national_id' => self::NID]);

        $response->assertStatus(422)
            ->assertJsonPath('errors', ['household_head.national_id' => [DuplicateNationalIdException::MESSAGE]])
            ->assertJsonPath('duplicate.matches', []);
        $this->assertStringNotContainsString($existing['person_code'], $response->getContent());
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])
            ->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.matches', []);
    }

    public function test_duplicate_on_household_head_registration_is_blocked_safely(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);
        $before = [Family::count(), Person::count(), FamilyMembership::count()];

        $response = $this->register(['full_name' => 'محاولة مكررة', 'national_id' => self::NID]);

        $response->assertStatus(422)
            ->assertJsonPath('message', DuplicateNationalIdException::MESSAGE)
            ->assertJsonPath('errors', ['household_head.national_id' => [DuplicateNationalIdException::MESSAGE]])
            ->assertJsonPath('duplicate.matches.0.person_code', $existing['person_code'])
            ->assertJsonPath('duplicate.matches.0.full_name', 'صاحب الهوية الأصلي')
            ->assertJsonPath('duplicate.matches.0.family.family_code', $existing['family_code'])
            ->assertJsonPath('duplicate.matches.0.family.is_household_head', true);
        $this->assertDuplicatePayloadIsSafe($response);
        // Nothing created — the whole registration rolled back.
        $this->assertSame($before, [Family::count(), Person::count(), FamilyMembership::count()]);
    }

    public function test_duplicate_on_add_member_is_blocked_without_merge_or_move(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);
        $other = $this->family('أسرة أخرى');
        $person = Person::where('person_code', $existing['person_code'])->sole();
        $fields = ['full_name', 'national_id', 'gender', 'birth_date', 'updated_at'];
        $snapshot = array_intersect_key($person->getRawOriginal(), array_flip($fields));
        $memberships = FamilyMembership::orderBy('id')->get(['family_id', 'person_id', 'is_active', 'is_household_head'])->toArray();

        $response = $this->addMember($other['family_code'], ['full_name' => 'نفس الشخص؟', 'national_id' => self::NID]);

        $response->assertStatus(422)
            ->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]])
            ->assertJsonPath('duplicate.matches.0.person_code', $existing['person_code'])
            ->assertJsonPath('duplicate.matches.0.family.family_code', $existing['family_code']);
        $this->assertDuplicatePayloadIsSafe($response);
        // Existing Person untouched, not attached to the other Family, no new Person.
        $this->assertSame($snapshot, array_intersect_key($person->fresh()->getRawOriginal(), array_flip($fields)));
        $this->assertSame($memberships, FamilyMembership::orderBy('id')->get(['family_id', 'person_id', 'is_active', 'is_household_head'])->toArray());
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertDatabaseMissing('persons', ['full_name' => 'نفس الشخص؟']);
    }

    public function test_the_same_rule_applies_when_a_national_id_is_changed(): void
    {
        $this->family('صاحب الهوية الأصلي', self::NID);
        $other = $this->family('شخص آخر', '913570250');
        $admin = $this->user('SUPER_ADMIN');
        $admin->givePermissionTo('person.national-id.update');

        // National ID changes go through the dedicated correction (AUTH-ADR-059).
        $this->actingAs($admin)->putJson("/api/v1/people/{$other['person_code']}/national-id", ['national_id' => self::NID, 'national_id_confirmation' => self::NID])
            ->assertStatus(422)->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]]);
        $this->assertSame('913570250', Person::where('person_code', $other['person_code'])->value('national_id'));
        // An equivalent formatting of a held value is the same value.
        $this->actingAs($admin)->putJson("/api/v1/people/{$other['person_code']}/national-id", ['national_id' => '913 570 246', 'national_id_confirmation' => '913 570 246'])
            ->assertStatus(422)->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]]);
        // An invalid replacement is refused; nothing changes.
        $this->actingAs($admin)->putJson("/api/v1/people/{$other['person_code']}/national-id", ['national_id' => 'SYN-OTHER-01', 'national_id_confirmation' => 'SYN-OTHER-01'])
            ->assertStatus(422)->assertJsonPath('errors.national_id.0', RegistryNationalId::MESSAGE);
        $this->assertSame('913570250', Person::where('person_code', $other['person_code'])->value('national_id'));
        // Re-saving a Person's own National ID is not a duplicate.
        $this->actingAs($admin)->putJson("/api/v1/people/{$other['person_code']}/national-id", ['national_id' => '913570250', 'national_id_confirmation' => '913570250'])->assertOk();
    }

    public function test_pre_check_is_exact_and_never_echoes_the_national_id(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);

        $hit = $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])->assertOk();
        $hit->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.matches.0.person_code', $existing['person_code'])
            ->assertJsonPath('data.matches.0.family.family_code', $existing['family_code']);
        $this->assertNoLeak($hit, [self::NID]);

        // The same value in another formatting is the same National ID (canonical check).
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => '٩١٣-٥٧٠-٢٤٦'])
            ->assertOk()->assertJsonPath('data.exists', true);
        // No partial / prefix / fuzzy matching: another valid value is no match,
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => '913570247'])
            ->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.matches', []);
        // and what is not a National ID is refused, never searched.
        foreach (['91357024', '9135702460', 'SYN-913570246', '%'] as $partial) {
            $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => $partial])
                ->assertStatus(422)->assertJsonMissingPath('data.exists');
        }
        // Never via the URL: there is no GET check (the path is just an unknown Person).
        $this->actingAs($this->staff)->getJson('/api/v1/people/national-id-check?national_id='.self::NID)
            ->assertNotFound()->assertJsonMissingPath('data.exists');
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', [])->assertStatus(422);
    }

    public function test_pre_check_is_only_for_users_who_create_persons(): void
    {
        $this->family('صاحب الهوية الأصلي', self::NID);

        // DATA_ENTRY and SOCIAL_WORKER-like creators: allowed (DATA_ENTRY has person.create).
        $this->actingAs($this->user('DATA_ENTRY'))->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])->assertOk();
        foreach (['REVIEWER', 'REPORTS_VIEWER', 'SOCIAL_WORKER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])->assertForbidden();
        }
        Auth::forgetGuards();
        $this->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])->assertUnauthorized();
    }

    public function test_pre_check_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => sprintf('9135%05d', $i)])->assertOk();
        }
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => '913599999'])->assertStatus(429);
    }
}
