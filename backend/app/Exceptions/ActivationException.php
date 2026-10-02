<?php

namespace App\Exceptions;

use App\Enums\ActivationError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Family activation step was refused (docs/11 §30a). Renders the public
 * contract only — `{message, code}` — never an internal reason.
 */
class ActivationException extends RuntimeException
{
    public function __construct(public readonly ActivationError $error, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($error->message());
    }

    public function render(Request $request): JsonResponse
    {
        return self::response($this->error, $this->retryAfterSeconds);
    }

    public static function response(ActivationError $error, ?int $retryAfterSeconds = null): JsonResponse
    {
        $body = ['message' => $error->message(), 'code' => $error->value];
        if ($retryAfterSeconds !== null) {
            $body['retry_after_seconds'] = $retryAfterSeconds;
        }

        return response()->json($body, $error->status())->header('Cache-Control', 'no-store');
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
