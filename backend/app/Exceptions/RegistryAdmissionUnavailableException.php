<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A direct admission that failed closed (FB-FAMILY-ADD-01 ADD-WP-07B-2a-F; ADD-WP-07B-2R
 * D-E2): 503 REGISTRY_UNAVAILABLE with a correlation id, and nothing else. Thrown only by
 * RegistryAdmissionFailure::run(), after the failure was logged there in sanitized form.
 *
 * - It never wraps the original exception (no previous): a reporter or renderer walking
 *   the chain cannot reach SQL, bound values or a driver message.
 * - report() does nothing, so the default handler never logs it or its stack trace.
 * - The failure code stays in the log; the client sees the public code only.
 */
class RegistryAdmissionUnavailableException extends RuntimeException
{
    public const CODE = 'REGISTRY_UNAVAILABLE';

    public const MESSAGE = 'تعذّر إتمام الإضافة حاليًا. لم يتغيّر شيء، أعد المحاولة لاحقًا.';

    public const RETRY_AFTER_SECONDS = 5;

    public function __construct(public readonly string $correlationId, public readonly bool $retryable)
    {
        parent::__construct(self::MESSAGE);
    }

    /** Already logged, sanitized, by RegistryAdmissionFailure. */
    public function report(): void {}

    public function render(): JsonResponse
    {
        $response = response()->json(['message' => self::MESSAGE, 'code' => self::CODE, 'correlation_id' => $this->correlationId], 503)
            ->header('Cache-Control', 'no-store, private');

        return $this->retryable ? $response->header('Retry-After', (string) self::RETRY_AFTER_SECONDS) : $response;
    }
}
