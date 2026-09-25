<?php

namespace Database\Factories;

use App\Enums\FamilyStatus;
use App\Enums\RegistrationSource;
use App\Models\Clan;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'family_code' => 'FAM-'.fake()->unique()->numerify('######'),
            // The Clan created by the clan-structure migration.
            'clan_id' => fn () => Clan::where('code', Clan::AL_BREEM)->value('id'),
            'status' => FamilyStatus::ACTIVE->value,
            'registration_date' => fake()->date(),
            'registration_source' => fake()->randomElement(RegistrationSource::cases())->value,
            'paper_form_no' => null,
            'notes' => null,
        ];
    }
}
