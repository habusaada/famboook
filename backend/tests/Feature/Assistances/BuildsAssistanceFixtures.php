<?php

namespace Tests\Feature\Assistances;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentDomain;
use App\Models\AssessmentResult;
use App\Models\Assistance;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyNeed;
use App\Models\FamilyResidence;
use App\Models\NeedCategory;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\AssessmentDomainSeeder;
use Database\Seeders\AssistanceCategorySeeder;
use Database\Seeders\DisabilityTypeSeeder;
use Database\Seeders\NeedCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

/**
 * Synthetic fixtures for the Assistance V1-A tests. All names, codes and
 * values are invented.
 */
trait BuildsAssistanceFixtures
{
    protected User $user;

    protected function setUpAssistanceFixtures(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AssistanceCategorySeeder::class);
        $this->seed(NeedCategorySeeder::class);
        $this->seed(AssessmentDomainSeeder::class);
        $this->seed(DisabilityTypeSeeder::class);

        $this->user = $this->user('ADMINISTRATOR', 'مدير تجريبي');
    }

    protected function user(string $role, ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A family with `$members` living active members (the first is head),
     * a current residence and optional overrides.
     *
     * @param  array<string, mixed>  $options
     */
    protected function family(int $members = 3, array $options = []): Family
    {
        $family = Family::factory()->create(array_filter(['status' => $options['status'] ?? null]));
        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'displacement_status' => $options['displacement'] ?? 'NOT_DISPLACED',
            'displacement_location_text' => $options['location'] ?? null,
        ]);

        for ($i = 0; $i < $members; $i++) {
            $person = Person::factory()->create([
                'full_name' => ($options['name'] ?? 'فرد تجريبي').' '.$i,
                'birth_date' => '1990-01-01',
                'gender' => 'FEMALE',
                'national_id' => $options['national_id'] ?? null,
                'mobile' => $options['mobile'] ?? null,
            ]);
            FamilyMembership::factory()->create([
                'family_id' => $family->id,
                'person_id' => $person->id,
                'is_household_head' => $i === 0,
            ]);
        }

        return $family;
    }

    protected function addMember(Family $family, array $attributes = [], bool $active = true): Person
    {
        $person = Person::factory()->create(['birth_date' => '1990-01-01', 'gender' => 'FEMALE', ...$attributes]);
        FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
            'is_active' => $active,
            'ended_at' => $active ? null : '2026-09-01',
        ]);

        return $person;
    }

    protected function healthRecord(Person $person, string $type, array $attributes = []): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $person->id,
            'type' => $type,
            'condition_name' => $type === 'CHRONIC_DISEASE' ? ($attributes['condition_name'] ?? 'مرض اختباري') : null,
            'disability_type_id' => $type === 'DISABILITY' ? 1 : null,
            ...$attributes,
        ]);
    }

    protected function need(Family $family, array $attributes = []): FamilyNeed
    {
        return FamilyNeed::create([
            'family_id' => $family->id,
            'need_category_id' => NeedCategory::where('code', $attributes['category'] ?? 'FOOD')->value('id'),
            'title' => $attributes['title'] ?? 'طرد غذائي',
            'description' => $attributes['description'] ?? null,
            'priority' => $attributes['priority'] ?? 'HIGH',
            'status' => $attributes['status'] ?? 'OPEN',
            'person_id' => $attributes['person_id'] ?? null,
            'resolved_at' => ($attributes['status'] ?? 'OPEN') === 'OPEN' ? null : now(),
            'closure_reason' => ($attributes['status'] ?? null) === 'CLOSED' ? 'سبب' : null,
        ]);
    }

    protected function assessment(Family $family, string $domain, string $rating, string $date, bool $completed = true, ?string $notes = null): Assessment
    {
        $assessment = Assessment::create([
            'family_id' => $family->id,
            'assessment_date' => $date,
            'status' => AssessmentStatus::DRAFT,
            'general_notes' => $notes,
        ]);
        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'assessment_domain_id' => AssessmentDomain::where('code', $domain)->value('id'),
            'rating' => $rating,
            'notes' => $notes,
        ]);
        if ($completed) {
            $assessment->update(['status' => AssessmentStatus::COMPLETED, 'completed_at' => now()]);
        }

        return $assessment;
    }

    protected function assistancePayload(array $overrides = []): array
    {
        return [
            'title' => 'حزمة إيواء طارئة',
            'category_code' => 'SHELTER',
            'assistance_type' => 'IN_KIND',
            'provider_name' => 'مبادرة مجتمعية تجريبية',
            'target_beneficiaries' => 100,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'description' => 'وصف تجريبي',
            'items' => [
                ['item_name' => 'فرشة', 'quantity_per_beneficiary' => 4, 'unit' => 'قطعة'],
                ['item_name' => 'بطانية', 'quantity_per_beneficiary' => 4, 'unit' => 'قطعة'],
            ],
            ...$overrides,
        ];
    }

    protected function createAssistance(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/v1/assistances', $this->assistancePayload($overrides));
    }

    protected function openAssistance(array $overrides = []): Assistance
    {
        $id = $this->createAssistance($overrides)->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")->assertOk();

        return Assistance::where('uuid', $id)->firstOrFail();
    }

    protected function preview(Assistance $assistance, array $criteria, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/targeting-preview", ['criteria' => $criteria, ...$extra]);
    }

    /** @return list<string> matching family codes (sorted) */
    protected function matching(Assistance $assistance, array $criteria): array
    {
        return array_column(
            $this->preview($assistance, $criteria, null, ['per_page' => 50])->assertOk()->json('data'),
            'family_code'
        );
    }
}
