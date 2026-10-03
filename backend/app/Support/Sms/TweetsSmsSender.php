<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;
use App\Enums\SmsFailureOutcome as Outcome;
use App\Enums\SmsFailureReason as Reason;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Production SMS driver: TweetsMS (docs/08 §16a, docs/11 §30a).
 *
 *   POST https://www.tweetsms.ps/api.php/office/sendsms   (JSON)
 *   {"api_key", "sender", "message", "to": "05XXXXXXXX"}
 *
 * Exactly ONE destination, in the registry's own local format (TweetsMS
 * accepts 05XXXXXXXX as stored; nothing is converted), and nothing else — no
 * groups, date or time, and never a name, a National ID or any family data.
 *
 * SENT means the provider's JSON result `code` is 999 — that is all. HTTP 2xx,
 * "status": "success" or a success message prove nothing alone. Every other
 * outcome throws a CLASSIFIED SmsDeliveryException (see self::CODES).
 *
 * Transport: Laravel HTTP client, TLS verification kept, redirects refused,
 * connect timeout 3 s and total timeout 8 s by default. NO automatic retry:
 * TweetsMS has no idempotency key and a second OTP SMS is worse than a missed
 * one — the user's resend is the retry. A failure that may have happened
 * after the request reached TweetsMS (a timeout, a broken connection) is
 * UNKNOWN.
 *
 * FAILS CLOSED: without an API key or a sender nothing is sent (and the
 * application still boots). The key travels only in the request body; this
 * class logs nothing — the caller logs the safe failure codes.
 */
final class TweetsSmsSender implements SmsSender
{
    public const DEFAULT_ENDPOINT = 'https://www.tweetsms.ps/api.php/office/sendsms';

    public const SUCCESS = 999;

    /** Documented TweetsMS result codes → [outcome, reason]. */
    public const CODES = [
        -126 => [Outcome::TEMPORARY_FAILURE, Reason::PROVIDER_BUSY],
        -124 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::INSUFFICIENT_CREDIT],
        -110 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::INVALID_CREDENTIALS],
        -111 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::ACCOUNT_INACTIVE],
        -112 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::ACCOUNT_BLOCKED],
        -114 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::SENDING_STOPPED],
        -115 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::INVALID_SENDER],
        -116 => [Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::INVALID_SENDER],
        -100 => [Outcome::PERMANENT_FAILURE, Reason::MISSING_PARAMETERS],
        -120 => [Outcome::PERMANENT_FAILURE, Reason::INVALID_DESTINATION],
    ];

    /** cURL errors raised before any request could leave: no DNS, no connection. */
    private const NOT_SENT_CURL_ERRORS = [6, 7];

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly ?string $sender,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 8,
    ) {}

    /** @param  array<string, mixed>  $config  config('family_auth.sms.tweetsms') */
    public static function fromConfig(array $config): self
    {
        return new self(
            is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
            is_string($config['sender'] ?? null) ? $config['sender'] : null,
            (string) (($config['endpoint'] ?? null) ?: self::DEFAULT_ENDPOINT),
            max(1, (int) ($config['connect_timeout'] ?? 3)),
            max(1, (int) ($config['timeout'] ?? 8)),
        );
    }

    /** Whether everything needed to send is present (never the values). */
    public function configured(): bool
    {
        return trim((string) $this->apiKey) !== ''
            && trim((string) $this->sender) !== ''
            && str_starts_with($this->endpoint, 'https://');
    }

    public function send(SmsMessage $message): void
    {
        if (! $this->configured()) {
            throw SmsDeliveryException::unconfigured();
        }
        // One destination, exactly as the registry stores it.
        if (preg_match('/\A05[0-9]{8}\z/', $message->destination) !== 1 || trim($message->body) === '') {
            throw SmsDeliveryException::classified(Outcome::PERMANENT_FAILURE, Reason::INVALID_DESTINATION);
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withoutRedirecting()
                ->withOptions(['verify' => true])
                ->post($this->endpoint, [
                    'api_key' => $this->apiKey,
                    'sender' => $this->sender,
                    'message' => $message->body,
                    'to' => $message->destination,
                ]);
        } catch (ConnectionException $e) {
            throw self::transportFailure($e);
        } catch (Throwable) {
            throw SmsDeliveryException::classified(Outcome::UNKNOWN, Reason::TRANSPORT_UNCERTAIN);
        }

        $code = self::resultCode($response);
        if ($code === self::SUCCESS) {
            return;
        }

        throw self::failureFor($response, $code);
    }

    /**
     * The provider's application result code, or NULL when the response is
     * not a JSON object carrying an integer `code` (999 and "999" alike).
     */
    public static function resultCode(Response $response): ?int
    {
        if (! $response->successful()) {
            return null;
        }
        try {
            $body = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($body) || array_is_list($body) || ! array_key_exists('code', $body)) {
            return null;
        }
        $code = $body['code'];
        if (is_int($code)) {
            return $code;
        }

        return is_string($code) && preg_match('/\A-?[0-9]{1,6}\z/', $code) === 1 ? (int) $code : null;
    }

    private static function failureFor(Response $response, ?int $code): SmsDeliveryException
    {
        $status = $response->status();
        if ($status >= 500) {
            return SmsDeliveryException::classified(Outcome::TEMPORARY_FAILURE, Reason::HTTP_SERVER_ERROR);
        }
        if ($status >= 400) {
            return SmsDeliveryException::classified(Outcome::PROVIDER_CONFIGURATION_FAILURE, Reason::HTTP_CLIENT_ERROR);
        }
        if ($status < 200 || $status >= 300) {
            // A redirect we refused to follow, or anything else odd.
            return SmsDeliveryException::classified(Outcome::UNKNOWN, Reason::UNEXPECTED_HTTP_STATUS);
        }
        if ($code === null) {
            return SmsDeliveryException::classified(Outcome::UNKNOWN, Reason::MALFORMED_RESPONSE);
        }
        [$outcome, $reason] = self::CODES[$code] ?? [Outcome::UNKNOWN, Reason::UNRECOGNIZED_RESULT];

        return SmsDeliveryException::classified($outcome, $reason);
    }

    /**
     * Only a failure to resolve or to connect proves the request never left;
     * anything else — a timeout included — may have reached TweetsMS.
     */
    private static function transportFailure(ConnectionException $e): SmsDeliveryException
    {
        $previous = $e->getPrevious();
        $errno = $previous instanceof GuzzleConnectException ? ($previous->getHandlerContext()['errno'] ?? null) : null;

        return in_array($errno, self::NOT_SENT_CURL_ERRORS, true)
            ? SmsDeliveryException::classified(Outcome::TEMPORARY_FAILURE, Reason::CONNECTION_FAILED)
            : SmsDeliveryException::classified(Outcome::UNKNOWN, Reason::TRANSPORT_UNCERTAIN);
    }
}
