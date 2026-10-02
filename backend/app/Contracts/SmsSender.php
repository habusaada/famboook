<?php

namespace App\Contracts;

use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsMessage;

/**
 * Provider-neutral SMS delivery (docs/11 §30a). No vendor is chosen: the
 * binding comes from config('family_auth.sms.driver') and defaults to a
 * sender that always refuses, so nothing can be delivered — and Family
 * Portal activation cannot work — until a real provider is configured.
 */
interface SmsSender
{
    /**
     * Hands one message to the provider, synchronously.
     *
     * @throws SmsDeliveryException when the message was not accepted
     */
    public function send(SmsMessage $message): void;
}
