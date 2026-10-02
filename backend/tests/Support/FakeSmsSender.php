<?php

namespace Tests\Support;

use App\Contracts\SmsSender;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsMessage;

/**
 * A deterministic in-memory sender for tests: it records what would have
 * been delivered and can be told to fail. Tests read an OTP from here — never
 * from the database, a log or a security event.
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<SmsMessage> */
    public array $sent = [];

    /** How many send() calls were made, failed ones included. */
    public int $attempts = 0;

    public bool $failing = false;

    public function send(SmsMessage $message): void
    {
        $this->attempts++;
        if ($this->failing) {
            throw SmsDeliveryException::failed();
        }
        $this->sent[] = $message;
    }

    public function last(): ?SmsMessage
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    /** The six-digit code of the last delivered message. */
    public function lastCode(): ?string
    {
        return $this->last() !== null && preg_match('/(?<!\d)(\d{6})(?!\d)/', $this->last()->body, $m) === 1 ? $m[1] : null;
    }
}
