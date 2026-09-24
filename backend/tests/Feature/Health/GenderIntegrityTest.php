<?php

namespace Tests\Feature\Health;

use App\Enums\Gender;
use App\Enums\HealthRecordType;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * docs/03 §36: an active pregnancy/breastfeeding record blocks changing the
 * person's gender away from FEMALE. Nothing is closed automatically.
 */
class GenderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Person $mother;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $family = Family::factory()->create();
        $this->mother = Person::factory()->create(['gender' => 'FEMALE', 'full_name' => 'أم اختبار']);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $this->mother->id,
        ]);
    }

    private function maternal(HealthRecordType $type, ?string $endedAt = null): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $this->mother->id,
            'type' => $type->value,
            'condition_name' => null,
            'ended_at' => $endedAt,
        ]);
    }

    private function patchMother(array $payload)
    {
        $user = User::factory()->create();
        $user->assignRole('DATA_ENTRY');

        return $this->actingAs($user)->patchJson("/api/v1/people/{$this->mother->person_code}", $payload);
    }

    public function test_female_to_male_rejected_with_active_pregnancy(): void
    {
        $record = $this->maternal(HealthRecordType::PREGNANCY);

        $this->patchMother(['gender' => 'MALE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gender')
            ->assertJsonPath('errors.gender.0', 'لا يمكن تغيير جنس هذا الشخص لوجود سجل حمل أو رضاعة نشط. أغلق السجل أولًا ثم صحّح الجنس.');

        $this->assertSame(Gender::FEMALE, $this->mother->fresh()->gender);
        // The health record is untouched: not closed, not modified.
        $this->assertNull($record->fresh()->ended_at);
        $this->assertEquals($record->updated_at, $record->fresh()->updated_at);
    }

    public function test_female_to_male_rejected_with_active_breastfeeding(): void
    {
        $record = $this->maternal(HealthRecordType::BREASTFEEDING);

        $this->patchMother(['gender' => 'MALE', 'full_name' => 'اسم جديد'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gender');

        // The whole request is rejected, including the name change.
        $fresh = $this->mother->fresh();
        $this->assertSame(Gender::FEMALE, $fresh->gender);
        $this->assertSame('أم اختبار', $fresh->full_name);
        $this->assertNull($record->fresh()->ended_at);
    }

    public function test_gender_correction_succeeds_after_records_are_closed(): void
    {
        $this->maternal(HealthRecordType::PREGNANCY, endedAt: '2026-01-01');
        $this->maternal(HealthRecordType::BREASTFEEDING, endedAt: '2026-02-01');

        $this->patchMother(['gender' => 'MALE'])
            ->assertOk()
            ->assertJsonPath('data.gender', 'MALE');

        $this->assertSame(Gender::MALE, $this->mother->fresh()->gender);
        $this->assertSame(2, PersonHealthRecord::count());
    }

    public function test_unrelated_edits_still_work_with_active_records(): void
    {
        $this->maternal(HealthRecordType::PREGNANCY);

        $this->patchMother([
            'full_name' => 'أم اختبار مصححة',
            'birth_date' => '1991-03-04',
            'mobile' => '0590000000',
            // Re-sending the unchanged gender is fine.
            'gender' => 'FEMALE',
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'أم اختبار مصححة');
    }

    public function test_other_active_record_types_do_not_block_gender_change(): void
    {
        PersonHealthRecord::factory()->create([
            'person_id' => $this->mother->id,
            'type' => HealthRecordType::CHRONIC_DISEASE->value,
            'condition_name' => 'سكري',
        ]);

        $this->patchMother(['gender' => 'MALE'])->assertOk();
    }

    public function test_rule_is_enforced_at_the_model_level_too(): void
    {
        $this->maternal(HealthRecordType::PREGNANCY);

        $this->expectException(ValidationException::class);

        $this->mother->update(['gender' => 'MALE']);
    }
}
