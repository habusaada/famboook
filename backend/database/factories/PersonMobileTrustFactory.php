<?php

namespace Database\Factories;

use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonMobileTrustFactory extends Factory
{
    protected $model = PersonMobileTrust::class;

    /** A verification that was opened and is not yet granted. */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            // A synthetic 64-hex value: factories never need the real key.
            'mobile_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'mobile_last2' => fake()->numerify('##'),
            'key_version' => 1,
            'status' => MobileTrustStatus::PENDING_VERIFICATION->value,
        ];
    }

    public function trusted(): static
    {
        return $this->state(fn () => [
            'status' => MobileTrustStatus::TRUSTED->value,
            'verification_method' => MobileVerificationMethod::IN_PERSON->value,
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ]);
    }

    public function stale(): static
    {
        return $this->trusted()->state(fn () => [
            'status' => MobileTrustStatus::STALE->value,
            'stale_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->trusted()->state(fn () => [
            'status' => MobileTrustStatus::REVOKED->value,
            'revoked_by' => User::factory(),
            'revoked_at' => now(),
            'revoke_reason' => 'ADMINISTRATIVE',
        ]);
    }
}
