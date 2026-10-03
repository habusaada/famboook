<?php

namespace App\Support\Sms;

use App\Enums\SmsFailureOutcome;
use App\Enums\SmsFailureReason;
use RuntimeException;

/**
 * The SMS was not accepted for delivery. It carries a CLASS (outcome) and a
 * safe reason code; the message is fixed text built from them — never the
 * destination, the text, a code or anything the provider returned.
 */
class SmsDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly SmsFailureOutcome $outcome,
        public readonly SmsFailureReason $reason,
    ) {
        parent::__construct("SMS not delivered: {$outcome->value} ({$reason->value}).");
    }

    public static function classified(SmsFailureOutcome $outcome, SmsFailureReason $reason): self
    {
        return new self($outcome, $reason);
    }

    public static function unconfigured(): self
    {
        return new self(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::NOT_CONFIGURED);
    }

    public static function unsafeEnvironment(): self
    {
        return new self(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::UNSAFE_ENVIRONMENT);
    }

    public static function failed(): self
    {
        return new self(SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::LOCAL_WRITE_FAILED);
    }
}
