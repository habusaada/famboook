<?php

namespace App\Support\Sms;

use RuntimeException;

/**
 * The SMS was not accepted for delivery. The message is a fixed reason:
 * never the destination, the text or a code.
 */
class SmsDeliveryException extends RuntimeException
{
    public static function unconfigured(): self
    {
        return new self('No SMS provider is configured.');
    }

    public static function unsafeEnvironment(): self
    {
        return new self('The development SMS log is not available in this environment.');
    }

    public static function failed(): self
    {
        return new self('The SMS could not be handed to the provider.');
    }
}
