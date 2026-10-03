<?php

namespace App\Console\Commands;

use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsDispatcher;
use App\Support\Sms\SmsMessage;
use App\Support\Sms\TweetsSmsSender;
use Illuminate\Console\Command;

/**
 * Checks the SMS configuration on a server (docs/08 §16a) without revealing
 * it: the driver, and whether an API key and a sender are set — YES/NO,
 * never the values. Nothing is sent by default.
 *
 * --send-test=05XXXXXXXX sends ONE fixed test text (never an OTP; the OTP
 * challenge system is not touched) to that number, after a confirmation when
 * the shell is interactive, and prints SENT or the safe outcome and reason.
 * The full number is never printed.
 */
class SmsCheck extends Command
{
    public const TEST_MESSAGE = 'رسالة اختبار من Famboook';

    protected $signature = 'famboook:sms-check
        {--send-test= : Send one test SMS to this 05XXXXXXXX number}';

    protected $description = 'Check the SMS configuration (no values shown); optionally send one test SMS.';

    public function handle(SmsDispatcher $sms): int
    {
        $driver = (string) config('family_auth.sms.driver');
        $tweetsms = (array) config('family_auth.sms.tweetsms');
        $hasKey = trim((string) ($tweetsms['api_key'] ?? '')) !== '';
        $hasSender = trim((string) ($tweetsms['sender'] ?? '')) !== '';

        $this->line('Driver:  '.($driver !== '' ? $driver : '(none)'));
        $this->line('API key: '.($hasKey ? 'YES' : 'NO'));
        $this->line('Sender:  '.($hasSender ? 'YES' : 'NO'));

        $problem = match (true) {
            $driver === '' => 'FAMILY_SMS_DRIVER is empty: no SMS can be sent.',
            $driver === 'tweetsms' && ! TweetsSmsSender::fromConfig($tweetsms)->configured() => 'TweetsMS configuration is incomplete: set TWEETSMS_API_KEY and TWEETSMS_SENDER (and an https endpoint).',
            ! in_array($driver, ['tweetsms', 'log'], true) => 'FAMILY_SMS_DRIVER is not a known driver: no SMS can be sent.',
            default => null,
        };
        if ($problem !== null) {
            $this->error('Configuration: INCOMPLETE — '.$problem);

            return self::FAILURE;
        }
        $this->info('Configuration: OK');
        if ($driver === 'log') {
            $this->warn('The log driver writes to a local file; it is not a Production driver.');
        }

        $destination = $this->option('send-test');
        if ($destination === null) {
            return self::SUCCESS;
        }

        return $this->sendTest($sms, (string) $destination);
    }

    private function sendTest(SmsDispatcher $sms, string $destination): int
    {
        if (preg_match('/\A05[0-9]{8}\z/', $destination) !== 1) {
            $this->error('--send-test must be a local mobile number: 05 followed by 8 digits.');

            return self::FAILURE;
        }
        $masked = '05******'.substr($destination, -2);
        if ($this->input->isInteractive() && ! $this->confirm("Send one test SMS to {$masked}?", false)) {
            $this->line('Nothing sent.');

            return self::FAILURE;
        }

        $failure = $sms->dispatch(
            new SmsMessage($destination, self::TEST_MESSAGE, 'SMS_CHECK'),
            static fn (?SmsDeliveryException $failure) => null,
        );
        if ($failure === null) {
            $this->info("SENT to {$masked}");

            return self::SUCCESS;
        }
        $this->error("NOT SENT: {$failure->outcome->value} ({$failure->reason->value})");

        return self::FAILURE;
    }
}
