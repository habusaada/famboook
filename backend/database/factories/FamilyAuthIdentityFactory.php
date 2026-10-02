<?php

namespace Database\Factories;

use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Models\FamilyAuthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyAuthIdentityFactory extends Factory
{
    protected $model = FamilyAuthIdentity::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->familySide(),
            // A synthetic 64-hex value: factories never need the real key.
            'login_key' => hash('sha256', fake()->unique()->uuid()),
            'key_version' => 1,
            'status' => AuthIdentityStatus::ACTIVE->value,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => AuthIdentityStatus::SUSPENDED->value]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => [
            'status' => AuthIdentityStatus::SUPERSEDED->value,
            'superseded_at' => now(),
            'supersede_reason' => AuthIdentitySupersedeReason::NATIONAL_ID_CORRECTED->value,
        ]);
    }
}
