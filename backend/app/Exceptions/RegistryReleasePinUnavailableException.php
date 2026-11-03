<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The release pin could not be taken or proven (FB-FAMILY-ADD-01 ADD-WP-07B-2b). Nothing
 * was decided: the admission fails closed. Carries a controlled detail code only, never
 * a pid, a nonce, SQL or a driver message. RegistryAdmissionFailure::run() logs the
 * detail and answers the sanitized 503.
 */
class RegistryReleasePinUnavailableException extends RuntimeException
{
    /** The connections are not PostgreSQL: advisory locks and pg_locks do not exist. */
    public const NOT_POSTGRESQL = 'PIN_NOT_POSTGRESQL';

    /** Called outside a transaction: a transaction-level lock would end at once. */
    public const NOT_IN_TRANSACTION = 'PIN_NOT_IN_TRANSACTION';

    /** Only READ COMMITTED gives the fresh snapshots the admission relies on (D-F5). */
    public const ISOLATION_UNSUPPORTED = 'PIN_ISOLATION_UNSUPPORTED';

    /** A publication holds or waits for the pin exclusively. Retryable. */
    public const BUSY = 'PIN_BUSY';

    /** The random nonce lock was taken by another transaction (about 1 in 2^31). Retryable. */
    public const NONCE_BUSY = 'PIN_NONCE_BUSY';

    /** The lookup-side verification could not be called or answered unexpectedly (e.g. DBA-3 not applied). */
    public const VERIFIER_UNAVAILABLE = 'PIN_VERIFIER_UNAVAILABLE';

    /** The verifier did not see this transaction's pin: another database, another session, or no pin. */
    public const NOT_PROVEN = 'PIN_NOT_PROVEN';

    private const RETRYABLE = [self::BUSY, self::NONCE_BUSY];

    public const DETAILS = [
        self::NOT_POSTGRESQL, self::NOT_IN_TRANSACTION, self::ISOLATION_UNSUPPORTED, self::BUSY, self::NONCE_BUSY,
        self::VERIFIER_UNAVAILABLE, self::NOT_PROVEN,
    ];

    public readonly bool $retryable;

    public function __construct(public readonly string $detail)
    {
        if (! in_array($detail, self::DETAILS, true)) {
            throw new \InvalidArgumentException('Unknown release pin failure.');
        }
        $this->retryable = in_array($detail, self::RETRYABLE, true);
        parent::__construct($detail);
    }
}
