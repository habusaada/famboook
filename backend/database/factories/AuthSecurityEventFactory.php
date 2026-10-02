<?php

namespace Database\Factories;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Models\AuthSecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthSecurityEventFactory extends Factory
{
    protected $model = AuthSecurityEvent::class;

    public function definition(): array
    {
        return [
            'event_type' => AuthSecurityEventType::LOGIN_FAILED->value,
            'outcome' => AuthSecurityEventOutcome::FAILURE->value,
            'reason_code' => null,
            'metadata' => null,
        ];
    }
}
