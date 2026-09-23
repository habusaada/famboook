<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyResidence;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyResidenceFactory extends Factory
{
    protected $model = FamilyResidence::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'residence_type' => null,
            'governorate' => fake()->city(),
            'city' => fake()->city(),
            'area' => null,
            'neighborhood' => null,
            'address_text' => fake()->address(),
            'latitude' => null,
            'longitude' => null,
            'displacement_status' => null,
            'started_at' => fake()->date(),
            'ended_at' => null,
            'is_current' => true,
            'source' => null,
            'notes' => null,
        ];
    }
}
