<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyMembershipFactory extends Factory
{
    protected $model = FamilyMembership::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'person_id' => Person::factory(),
            'is_household_head' => false,
            'started_at' => fake()->date(),
            'ended_at' => null,
            'is_active' => true,
            'end_reason' => null,
            'notes' => null,
        ];
    }

    public function householdHead(): static
    {
        return $this->state(fn () => ['is_household_head' => true]);
    }
}
