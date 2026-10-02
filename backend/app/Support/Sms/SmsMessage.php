<?php

namespace App\Support\Sms;

/**
 * One SMS to deliver: the normalized destination, the text and a purpose
 * code. Immutable and in memory only — it is never stored, queued or put in
 * a security event, and a sender must not write the destination or the body
 * to the application log.
 */
final readonly class SmsMessage
{
    public function __construct(
        #[\SensitiveParameter] public string $destination,
        #[\SensitiveParameter] public string $body,
        public string $purpose,
    ) {}
}
