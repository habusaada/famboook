<?php

namespace App\Support\FamilyAuth;

use App\Enums\OtpFailure;
use App\Models\AuthOtpChallenge;

/**
 * The outcome of an OTP operation: success, or one internal failure reason.
 * It never carries the code. The challenge is present whenever one exists —
 * also on DELIVERY_FAILED, where the challenge was created but no SMS left.
 */
final readonly class OtpResult
{
    private function __construct(public ?OtpFailure $failure, public ?AuthOtpChallenge $challenge) {}

    public static function ok(AuthOtpChallenge $challenge): self
    {
        return new self(null, $challenge);
    }

    public static function failed(OtpFailure $failure, ?AuthOtpChallenge $challenge = null): self
    {
        return new self($failure, $challenge);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
