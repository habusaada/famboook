<?php

namespace App\Support\Sms;

use App\Enums\SmsFailureOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The operational log line of an SMS that was not delivered (docs/08 §16a).
 * Structured and SAFE: the provider, the outcome, the reason, the purpose
 * and the last two digits of the destination. Never the API key, the body,
 * the OTP, the full destination or any provider / transport message.
 *
 * A provider-configuration failure (credentials, account, sender, credit,
 * nothing configured) means EVERY send fails until an operator acts: it is
 * logged as critical, at most once per reason per 10 minutes, so an outage
 * does not flood the log. Other failures are warnings.
 */
final class SmsDeliveryLog
{
    public const CRITICAL_EVERY_SECONDS = 600;

    public static function failed(SmsDeliveryException $failure, SmsMessage $message): void
    {
        $context = [
            'provider' => (string) (config('family_auth.sms.driver') ?: 'none'),
            'outcome' => $failure->outcome->value,
            'reason' => $failure->reason->value,
            'purpose' => $message->purpose,
            'mobile_last2' => substr($message->destination, -2),
        ];

        if ($failure->outcome !== SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE) {
            Log::warning('Family SMS not delivered.', $context);

            return;
        }

        try {
            $first = Cache::add('sms-critical|'.$failure->reason->value, true, self::CRITICAL_EVERY_SECONDS);
        } catch (Throwable) {
            // No cache: log every time rather than never.
            $first = true;
        }
        if ($first) {
            Log::critical('Family SMS delivery is failing: the SMS provider needs operator action.', $context);
        }
    }
}
