<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\RegistryAdmissionReferenceException;
use App\Exceptions\RegistryAdmissionUnavailableException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * The failure contract of the future admission action (FB-FAMILY-ADD-01 ADD-WP-07B-2a-F;
 * ADD-WP-07B-2R D-E2). INTERNAL: nothing calls it yet; there is no admission action,
 * controller or route, and no global exception handler is changed.
 *
 * run() wraps the WHOLE admission (its transaction included):
 * - An expected refusal (a class listed by the caller, and always a reference refusal)
 *   passes through unchanged; so does an already sanitized failure.
 * - Anything else fails closed: one log line with a correlation id, a controlled failure
 *   code, the exception class and a validated SQLSTATE, and never the message, the SQL,
 *   the bound values or the trace; then RegistryAdmissionUnavailableException (503).
 * - It always rethrows: an enclosing transaction still rolls back, and nothing is
 *   answered as a success.
 * - Retryable: lock timeout (55P03), deadlock (40P01) and serialization failure (40001),
 *   which roll the whole transaction back; the answer carries Retry-After.
 * - It writes no audit row: recording REGISTRY_UNAVAILABLE is the action's choice, outside
 *   the failed transaction, and that write may fail too.
 */
final class RegistryAdmissionFailure
{
    public const LOCK_TIMEOUT = 'LOCK_TIMEOUT';

    public const DEADLOCK = 'DEADLOCK';

    public const SERIALIZATION = 'SERIALIZATION';

    public const DATABASE_ERROR = 'DATABASE_ERROR';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    private const RETRYABLE = ['55P03' => self::LOCK_TIMEOUT, '40P01' => self::DEADLOCK, '40001' => self::SERIALIZATION];

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @param  class-string<Throwable>  ...$refusals  exceptions that are answers, not failures
     * @return T
     *
     * @throws RegistryAdmissionUnavailableException
     */
    public static function run(callable $work, string ...$refusals): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            foreach ([RegistryAdmissionReferenceException::class, RegistryAdmissionUnavailableException::class, ...$refusals] as $refusal) {
                if ($e instanceof $refusal) {
                    throw $e;
                }
            }
            throw self::failClosed($e);
        }
    }

    private static function failClosed(Throwable $e): RegistryAdmissionUnavailableException
    {
        $sqlstate = self::sqlstate($e);
        $code = $sqlstate === null ? ($e instanceof QueryException || $e instanceof PDOException ? self::DATABASE_ERROR : self::INTERNAL_ERROR)
            : (self::RETRYABLE[$sqlstate] ?? self::DATABASE_ERROR);
        $correlationId = (string) Str::uuid();

        Log::error('Registry admission failed closed.', [
            'correlation_id' => $correlationId,
            'failure' => $code,
            'exception' => $e::class,
            'sqlstate' => $sqlstate,
        ]);

        return new RegistryAdmissionUnavailableException($correlationId, isset(self::RETRYABLE[$sqlstate ?? '']));
    }

    /** The five-character SQLSTATE of a database failure, or NULL: never a message. */
    private static function sqlstate(Throwable $e): ?string
    {
        $state = match (true) {
            $e instanceof QueryException, $e instanceof PDOException => $e->errorInfo[0] ?? $e->getCode(),
            default => null,
        };

        return is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1 ? $state : null;
    }
}
