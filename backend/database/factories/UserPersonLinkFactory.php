<?php

namespace Database\Factories;

use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkType;
use App\Enums\UserPersonLinkVerificationMethod;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserPersonLinkFactory extends Factory
{
    protected $model = UserPersonLink::class;

    /** An ACTIVE link, as the V1 activation creates it. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->familySide(),
            'person_id' => Person::factory(),
            'link_type' => UserPersonLinkType::SELF->value,
            'status' => UserPersonLinkStatus::ACTIVE->value,
            'verification_method' => UserPersonLinkVerificationMethod::SYSTEM_OTP_ACTIVATION->value,
            'verified_by' => null,
            'verified_at' => now(),
            'activated_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => UserPersonLinkStatus::SUSPENDED->value,
            'suspended_at' => now(),
            'suspended_by' => User::factory(),
            'suspension_reason' => 'ADMINISTRATIVE',
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => UserPersonLinkStatus::ENDED->value,
            'ended_at' => now(),
            'end_reason' => 'ADMINISTRATIVE',
        ]);
    }
}
