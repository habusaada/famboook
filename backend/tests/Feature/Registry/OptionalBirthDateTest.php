<?php

namespace Tests\Feature\Registry;

use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Date of birth is optional on creation (Pilot Slice B): NULL means
 * unknown — never a placeholder (docs/03 §26) — and falls in the UNKNOWN
 * age band (docs/02 §75).
 */
class OptionalBirthDateTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    public function test_head_and_member_can_be_created_without_a_birth_date(): void
    {
        $response = $this->register(['full_name' => 'رب بلا تاريخ', 'birth_date' => null])->assertCreated();
        $familyCode = $response->json('data.family_code');
        $headCode = $response->json('data.members.0.person_code');
        $response->assertJsonPath('data.members.0.birth_date', null);

        // Omitted entirely, too.
        $member = $this->addMember($familyCode, ['full_name' => 'فرد بلا تاريخ', 'birth_date' => null])->assertCreated();
        $member->assertJsonPath('data.birth_date', null);
        $payloadWithoutDob = ['full_name' => 'فرد آخر بلا تاريخ', 'gender' => 'MALE', 'relationship_type_id' => \App\Models\RelationshipType::where('code', 'SON')->value('id')];
        $this->actingAs($this->staff)->postJson("/api/v1/families/{$familyCode}/members", $payloadWithoutDob)->assertCreated();

        $this->assertSame(3, Person::whereNull('birth_date')->count());
        $this->actingAs($this->staff)->getJson("/api/v1/people/{$headCode}")
            ->assertOk()->assertJsonPath('data.birth_date', null);
    }

    public function test_invalid_and_future_birth_dates_are_still_rejected(): void
    {
        $this->register(['birth_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['household_head.birth_date']);
        $this->register(['birth_date' => 'not-a-date'])
            ->assertStatus(422)->assertJsonValidationErrors(['household_head.birth_date']);

        $family = $this->family('رب أسرة');
        $this->addMember($family['family_code'], ['birth_date' => now()->addYear()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_unknown_birth_dates_count_in_the_unknown_age_band(): void
    {
        $response = $this->register(['full_name' => 'رب بلا تاريخ', 'birth_date' => null])->assertCreated();
        $this->addMember($response->json('data.family_code'), ['birth_date' => null])->assertCreated();
        $this->addMember($response->json('data.family_code'), ['birth_date' => '2015-06-01'])->assertCreated();

        $admin = $this->user('ADMINISTRATOR');
        $dashboard = $this->actingAs($admin)->getJson('/api/v1/dashboard?clan=AL_BREEM')->assertOk();
        $bands = collect($dashboard->json('data.demographics.age_bands'))->pluck('count', 'code');
        $this->assertSame(2, $bands['UNKNOWN']);
        $this->assertSame(1, $bands['AGE_6_17']);

        $report = $this->actingAs($admin)->getJson('/api/v1/reports/population?clan=AL_BREEM')->assertOk();
        $this->assertSame(2, collect($report->json('data.age_bands'))->firstWhere('code', 'UNKNOWN')['count']);
        $quality = $this->actingAs($admin)->getJson('/api/v1/reports/data-quality?clan=AL_BREEM')->assertOk();
        $this->assertSame(2, collect($quality->json('data.issues'))->firstWhere('code', 'PERSON_MISSING_BIRTH_DATE')['count']);
    }
}
