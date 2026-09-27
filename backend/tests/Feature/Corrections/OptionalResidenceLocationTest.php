<?php

namespace Tests\Feature\Corrections;

use App\Enums\DisplacementStatus;
use App\Models\FamilyResidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Registry\BuildsRegistryFixtures;
use Tests\TestCase;

/**
 * Pilot Readiness Slice C residence decision: current governorate / city
 * are optional (nullable in docs/02 §19 and docs/04). A paper form that
 * only gives the displacement location is recorded without fabricated
 * geographic values.
 */
class OptionalResidenceLocationTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    /** @param array<string, mixed> $residence */
    private function registerWith(array $residence)
    {
        return $this->actingAs($this->staff)->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01',
            'registration_source' => 'PAPER_FORM',
            'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة نازحة', 'gender' => 'MALE'],
            'residence' => $residence,
        ]);
    }

    public function test_displaced_family_keeps_its_displacement_location_without_governorate_or_city(): void
    {
        $response = $this->registerWith([
            'original_residence_text' => 'بني سهيلا – خانيونس',
            'displacement_status' => 'DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ])->assertCreated()
            ->assertJsonPath('data.residence.governorate', null)
            ->assertJsonPath('data.residence.city', null)
            ->assertJsonPath('data.residence.original_residence_text', 'بني سهيلا – خانيونس')
            ->assertJsonPath('data.residence.displacement_status', 'DISPLACED')
            ->assertJsonPath('data.residence.displacement_location_text', 'مواصي خانيونس');

        $residence = FamilyResidence::sole();
        $this->assertNull($residence->governorate);
        $this->assertNull($residence->city);
        $this->assertSame(DisplacementStatus::DISPLACED, $residence->displacement_status);

        $this->actingAs($this->staff)->getJson('/api/v1/families/'.$response->json('data.family_code'))
            ->assertOk()->assertJsonPath('data.residence.city', null);
    }

    public function test_unknown_governorate_and_city_may_be_sent_as_empty_or_null(): void
    {
        $this->registerWith(['governorate' => '', 'city' => null])->assertCreated();
        $this->registerWith(['governorate' => '   ', 'city' => ''])->assertCreated();

        $this->assertSame(2, FamilyResidence::whereNull('governorate')->whereNull('city')->count());
    }

    public function test_residence_correction_can_clear_governorate_and_city(): void
    {
        $code = $this->registerWith(['governorate' => 'خانيونس', 'city' => 'خانيونس'])->assertCreated()->json('data.family_code');

        $this->actingAs($this->staff)->patchJson("/api/v1/families/{$code}/residence", [
            'governorate' => null,
            'city' => '',
        ])->assertOk()
            ->assertJsonPath('data.residence.governorate', null)
            ->assertJsonPath('data.residence.city', null);
    }

    public function test_recorded_governorate_and_city_still_work(): void
    {
        $this->registerWith(['governorate' => 'خانيونس', 'city' => 'بني سهيلا', 'displacement_status' => 'NOT_DISPLACED'])
            ->assertCreated()
            ->assertJsonPath('data.residence.governorate', 'خانيونس')
            ->assertJsonPath('data.residence.city', 'بني سهيلا');

        // A displacement location still needs a DISPLACED status.
        $this->registerWith(['displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => 'مواصي'])
            ->assertStatus(422)->assertJsonValidationErrors(['residence.displacement_location_text']);
    }
}
