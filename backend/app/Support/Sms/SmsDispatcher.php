<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;
use App\Enums\SmsFailureOutcome;
use App\Enums\SmsFailureReason;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * WHEN an SMS is handed to the provider (docs/11 §30a, A′): after the HTTP
 * response, in the SAME PHP process, with NO queue.
 *
 * The public Family Auth routes that send an OTP switch the dispatcher to
 * deferred mode (middleware `sms.after-response`). A deferred message is kept
 * in this object's memory only and sent when the application terminates —
 * after the response was flushed to the client (PHP-FPM:
 * fastcgi_finish_request) — so the provider's latency or outage never shows
 * in the response time, and the plaintext OTP is never written to a queue, a
 * job table, the cache or the database.
 *
 * Anywhere else (an Artisan command, a direct service call) the message is
 * sent immediately and the caller learns the outcome.
 *
 * Every attempt ends in $report(?SmsDeliveryException): NULL when sent.
 * A failure is logged here, safely (SmsDeliveryLog); nothing is retried.
 */
final class SmsDispatcher
{
    private bool $deferring = false;

    private bool $hooked = false;

    /** @var list<array{0: SmsMessage, 1: Closure}> */
    private array $pending = [];

    public function __construct(private readonly Application $app) {}

    /** For the rest of this request, send after the response. */
    public function deferUntilResponseSent(): void
    {
        $this->deferring = true;
        if (! $this->hooked) {
            $this->hooked = true;
            $this->app->terminating(fn () => $this->flush());
        }
    }

    public function deferring(): bool
    {
        return $this->deferring;
    }

    /**
     * Sends now, or after the response when deferring. Returns the failure
     * of an IMMEDIATE send, else NULL (sent, or not attempted yet).
     *
     * @param  Closure(?SmsDeliveryException): void  $report
     */
    public function dispatch(SmsMessage $message, Closure $report): ?SmsDeliveryException
    {
        if ($this->deferring) {
            $this->pending[] = [$message, $report];

            return null;
        }

        return $this->sendNow($message, $report);
    }

    /** Sends everything deferred during this request; then stops deferring. */
    public function flush(): void
    {
        $this->deferring = false;
        while ($item = array_shift($this->pending)) {
            try {
                $this->sendNow(...$item);
            } catch (Throwable) {
                // After the response nothing may escape; the failure was
                // already reported or cannot be.
            }
        }
    }

    private function sendNow(SmsMessage $message, Closure $report): ?SmsDeliveryException
    {
        try {
            $this->app->make(SmsSender::class)->send($message);
        } catch (Throwable $e) {
            $failure = $e instanceof SmsDeliveryException
                ? $e
                : SmsDeliveryException::classified(SmsFailureOutcome::UNKNOWN, SmsFailureReason::UNEXPECTED_ERROR);
            SmsDeliveryLog::failed($failure, $message);
            $report($failure);

            return $failure;
        }
        $report(null);

        return null;
    }
}
