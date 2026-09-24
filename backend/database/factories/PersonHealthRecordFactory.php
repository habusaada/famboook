<?php

namespace Database\Factories;

use App\Enums\HealthRecordType;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonHealthRecordFactory extends Factory
{
    protected $model = PersonHealthRecord::class;

    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'type' => HealthRecordType::CHRONIC_DISEASE->value,
            'disability_type_id' => null,
            'condition_name' => fake()->unique()->word(),
            'details' => null,
            'started_at' => null,
            'ended_at' => null,
        ];
    }
}
