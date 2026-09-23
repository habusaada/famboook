<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\LifeStatus;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'person_code' => 'PER-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'national_id' => null,
            'gender' => fake()->randomElement(Gender::cases())->value,
            'birth_date' => fake()->date(max: '-1 years'),
            'life_status' => LifeStatus::ALIVE->value,
            'death_date' => null,
            'mobile' => null,
            'alternate_mobile' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }
}
