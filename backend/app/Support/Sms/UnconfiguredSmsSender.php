<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;

/**
 * The DEFAULT sender: it always refuses. With no driver configured nothing
 * is delivered, so Production can never send an OTP by accident — an SMS
 * provider must be selected and configured explicitly (docs/08 §16a).
 */
final class UnconfiguredSmsSender implements SmsSender
{
    public function send(SmsMessage $message): void
    {
        throw SmsDeliveryException::unconfigured();
    }
}
