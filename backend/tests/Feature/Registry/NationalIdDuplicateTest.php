<?php

namespace Tests\Feature\Registry;

use App\Exceptions\DuplicateNationalIdException;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Exact National ID duplicate prevention (docs/03 §21, AUTH-ADR-058):
 * enforced by the creation actions, exact match only, never echoes the
 * National ID, never merges or attaches the existing Person.
 */
class NationalIdDuplicateTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private const NID = 'SYN-9900112233';

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
    private function assertDuplicatePayloadIsSafe(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertStringNotContainsString(self::NID, $response->getContent());
        foreach ($response->json('duplicate.matches') as $match) {
            $this->assertSame(['person_code', 'full_name', 'family'], array_keys($match));
            $this->assertSame(['family_code', 'is_household_head', 'relationship'], array_keys($match['family']));
        }
    }

    public function test_new_and_missing_national_ids_are_accepted(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);

        $this->register(['full_name' => 'رب جديد', 'national_id' => 'SYN-NEW-0001'])->assertCreated();
        $this->register(['full_name' => 'رب بلا هوية', 'national_id' => null])->assertCreated();
        $this->addMember($existing['family_code'], ['national_id' => 'SYN-NEW-0002'])->assertCreated();
        // Missing National ID is never a duplicate, however often it is missing.
        $this->addMember($existing['family_code'], ['national_id' => null])->assertCreated();
        $this->addMember($existing['family_code'], [])->assertCreated();
        $this->assertSame(3, Person::whereNull('national_id')->count());
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
        $other = $this->family('شخص آخر', 'SYN-OTHER-01');
        $admin = $this->user('SUPER_ADMIN');
        $admin->givePermissionTo('person.national-id.update');

        $this->actingAs($admin)->patchJson("/api/v1/people/{$other['person_code']}", ['national_id' => self::NID])
            ->assertStatus(422)->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]]);
        $this->assertSame('SYN-OTHER-01', Person::where('person_code', $other['person_code'])->value('national_id'));
        // Re-saving a Person's own National ID is not a duplicate.
        $this->actingAs($admin)->patchJson("/api/v1/people/{$other['person_code']}", ['national_id' => 'SYN-OTHER-01'])->assertOk();
    }

    public function test_pre_check_is_exact_and_never_echoes_the_national_id(): void
    {
        $existing = $this->family('صاحب الهوية الأصلي', self::NID);

        $hit = $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => self::NID])->assertOk();
        $hit->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.matches.0.person_code', $existing['person_code'])
            ->assertJsonPath('data.matches.0.family.family_code', $existing['family_code']);
        $this->assertNoLeak($hit, [self::NID]);

        // No partial / prefix / fuzzy matching.
        foreach (['SYN-99001122', '9900112233', 'syn-9900112233', 'SYN-9900112234', '%'] as $partial) {
            $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => $partial])
                ->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.matches', []);
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
            $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => "SYN-RATE-{$i}"])->assertOk();
        }
        $this->actingAs($this->staff)->postJson('/api/v1/people/national-id-check', ['national_id' => 'SYN-RATE-X'])->assertStatus(429);
    }
}
