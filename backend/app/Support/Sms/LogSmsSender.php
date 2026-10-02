<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;

/**
 * LOCAL / TESTING ONLY: "delivers" an SMS by appending it to a dedicated
 * development file, so a developer can read the code they would have
 * received. It refuses to run in any other environment, whatever the
 * configuration says, so an OTP can never be written to disk in Production.
 *
 * The file is separate from the application log (laravel.log), the
 * destination is masked, and nothing is written anywhere else.
 */
final class LogSmsSender implements SmsSender
{
    public const SAFE_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(private readonly string $path) {}

    public function send(SmsMessage $message): void
    {
        if (! app()->environment(self::SAFE_ENVIRONMENTS)) {
            throw SmsDeliveryException::unsafeEnvironment();
        }

        $line = sprintf(
            "[%s] to=%s purpose=%s body=%s\n",
            now()->toIso8601String(),
            self::mask($message->destination),
            $message->purpose,
            $message->body,
        );

        $directory = dirname($this->path);
        if ((! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory))
            || @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw SmsDeliveryException::failed();
        }
    }

    /** Only the last two characters of the destination are ever written. */
    public static function mask(string $destination): string
    {
        return '********'.substr($destination, -2);
    }
}
