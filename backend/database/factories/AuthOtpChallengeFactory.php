<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthOtpChallengeFactory extends Factory
{
    protected $model = AuthOtpChallenge::class;

    /** An open ACTIVATION challenge to a trusted mobile of the same Person. */
    public function definition(): array
    {
        return [
            'purpose' => OtpPurpose::ACTIVATION->value,
            'person_id' => Person::factory(),
            'mobile_trust_id' => fn (array $attributes) => PersonMobileTrust::factory()->trusted()
                ->create(['person_id' => $attributes['person_id']])->id,
            // A synthetic 64-hex value: never a plaintext code.
            'code_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addSeconds(300),
            'attempts' => 0,
            'send_count' => 1,
            'last_sent_at' => now(),
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['verified_at' => now(), 'consumed_at' => now()]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['superseded_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['attempts' => 5, 'locked_at' => now()]);
    }
}
