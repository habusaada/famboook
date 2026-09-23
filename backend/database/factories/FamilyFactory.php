<?php

namespace Database\Factories;

use App\Enums\FamilyStatus;
use App\Enums\RegistrationSource;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'family_code' => 'FAM-'.fake()->unique()->numerify('######'),
            'status' => FamilyStatus::ACTIVE->value,
            'registration_date' => fake()->date(),
            'registration_source' => fake()->randomElement(RegistrationSource::cases())->value,
            'paper_form_no' => null,
            'notes' => null,
        ];
    }
}
