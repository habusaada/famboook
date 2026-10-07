<?php

namespace Database\Factories;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic Change Requests for tests (PWA-5a). The default is a SUBMITTED
 * request by the household head of a new Family; the payload is an
 * illustrative object, not a real type's schema.
 *
 * @extends Factory<ChangeRequest>
 */
class ChangeRequestFactory extends Factory
{
    protected $model = ChangeRequest::class;

    public function definition(): array
    {
        return [
            'family_id' => fn () => FamilyMembership::factory()->householdHead()->create()->family_id,
            'type' => ChangeRequestType::RESIDENCE_UPDATE,
            'payload_version' => 1,
            'status' => ChangeRequestStatus::SUBMITTED,
            'submitted_data' => ['example' => 'synthetic'],
            'submitted_by' => fn () => User::factory()->familySide(),
            'submitted_by_person_id' => fn (array $attributes) => FamilyMembership::query()
                ->where('family_id', $attributes['family_id'])->where('is_household_head', true)->value('person_id'),
            'submitted_at' => now(),
        ];
    }

    /** A Staff reviewer has opened the request. */
    public function underReview(?User $reviewer = null): static
    {
        return $this->state(function () use ($reviewer) {
            $reviewer ??= User::factory()->create();

            return ['status' => ChangeRequestStatus::UNDER_REVIEW, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()];
        });
    }

    /** Approved by the reviewer, not yet applied (APPROVED ≠ APPLIED). */
    public function approved(?User $staff = null): static
    {
        return $this->underReview($staff)->state(fn (array $attributes) => [
            'status' => ChangeRequestStatus::APPROVED,
            'approved_by' => $attributes['reviewed_by'],
            'approved_at' => now(),
        ]);
    }
}
