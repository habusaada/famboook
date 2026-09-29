<?php

namespace Database\Factories;

use App\Enums\RegistrationSource;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyHouseholdDeclarationFactory extends Factory
{
    protected $model = FamilyHouseholdDeclaration::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'declared_household_size' => fake()->numberBetween(1, 12),
            'declared_living_sons' => fake()->numberBetween(0, 5),
            'declared_living_daughters' => fake()->numberBetween(0, 5),
            'declared_at' => null,
            'source' => RegistrationSource::IMPORT->value,
            'is_current' => true,
            'notes' => null,
        ];
    }
}
