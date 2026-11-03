<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryReleasePinUnavailableException as Unavailable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The application side of the release pin (FB-FAMILY-ADD-01 ADD-WP-07B-2b; ADD-WP-07B-2R
 * D-F1, D-F3, D-F5). INTERNAL: nothing calls it and it is bound nowhere.
 *
 * In the admission transaction on $app:
 * 1. READ COMMITTED is required (D-F5).
 * 2. In ONE statement: pg_try_advisory_xact_lock_shared(PIN_KEY), 'FBCIV01P', which never
 *    waits (a publication that holds or waits for it exclusively, DBA-1, makes it fail at
 *    once: BUSY); pg_try_advisory_xact_lock(NONCE_NAMESPACE, nonce), exclusive, with a
 *    random nonce: a lock only this transaction holds; and pg_backend_pid().
 * 3. The verifier, on the registry's own session, must see — in its database, in one
 *    READ COMMITTED statement — a LIVE transaction of that pid holding BOTH locks, and
 *    name the active release.
 *
 * Mandatory lock order of the admission transaction (ADD-WP-07B-2b-F): this pin, then
 * Family FOR UPDATE, then the head's active membership FOR SHARE, then the child's
 * National ID advisory lock, then the Person FOR UPDATE (refusing an active member
 * before locking that Person). ADR §10a.
 *
 * Why the nonce: a pid alone proves nothing. Several admissions hold the shared pin at
 * once, so another session's pid would pass, and a pid can be reused after a backend
 * ends. The nonce lock exists only while THIS transaction is open, and the verifier
 * requires it in the same virtual transaction as the pin. The database column of
 * pg_locks proves the two sessions share a database: advisory locks of different
 * databases never conflict, so a registry elsewhere would make the pin meaningless,
 * and it fails NOT_PROVEN instead.
 *
 * Both locks are transaction-level: commit or rollback releases them, with no unlock
 * call. Database errors on $app propagate unchanged, for RegistryAdmissionFailure to
 * sanitize. Every verifier failure is VERIFIER_UNAVAILABLE.
 */
final class PostgresRegistryReleasePin implements RegistryReleasePin
{
    /** 'FBCIV01P' in ASCII, the single-bigint advisory key form; publish_release() uses 'FBCIV01A'. */
    public const PIN_KEY = 0x4642434956303150;

    /** 'FBPN' in ASCII: the two-integer advisory key namespace of the nonce. */
    public const NONCE_NAMESPACE = 0x4642504E;

    public const NONCE_MAX = 0x7FFFFFFF;

    /**
     * @param  ConnectionInterface  $app  THE connection of the admission transaction: a pin on any other session protects nothing
     * @param  (\Closure(): int)|null  $nonces  random_int() unless given; a fixed nonce is never a proof by itself, it only
     *                                          makes a collision reproducible in tests (the pid and transaction still must match)
     */
    public function __construct(
        private readonly ConnectionInterface $app,
        private readonly RegistryReleasePinVerifier $verifier,
        private readonly ?\Closure $nonces = null,
    ) {}

    public function pin(int $releaseId): void
    {
        if ($releaseId < 1) {
            throw new \InvalidArgumentException('A release id is positive.');
        }
        if ($this->app->getDriverName() !== 'pgsql') {
            throw new Unavailable(Unavailable::NOT_POSTGRESQL);
        }
        if ($this->app->transactionLevel() < 1) {
            throw new Unavailable(Unavailable::NOT_IN_TRANSACTION);
        }
        if ($this->app->selectOne("SELECT pg_catalog.current_setting('transaction_isolation') AS isolation")->isolation !== 'read committed') {
            throw new Unavailable(Unavailable::ISOLATION_UNSUPPORTED);
        }
        $nonce = $this->nonces === null ? random_int(1, self::NONCE_MAX) : ($this->nonces)();
        if (! is_int($nonce) || $nonce < 1 || $nonce > self::NONCE_MAX) {
            throw new \LogicException('A nonce is between 1 and 2^31 - 1.');
        }
        // ONE statement: the pin, the nonce and the pid come from the same backend, whatever
        // a pool in front of $app does between statements (ADD-WP-07B-2b-F).
        $locked = $this->app->selectOne('SELECT pg_catalog.pg_try_advisory_xact_lock_shared(?::bigint) AS pinned,
                pg_catalog.pg_try_advisory_xact_lock(?::integer, ?::integer) AS nonced, pg_catalog.pg_backend_pid() AS pid',
            [self::PIN_KEY, self::NONCE_NAMESPACE, $nonce]);
        if (! $locked->pinned) {
            throw new Unavailable(Unavailable::BUSY);
        }
        if (! $locked->nonced) {
            throw new Unavailable(Unavailable::NONCE_BUSY);
        }
        $pid = (int) $locked->pid;

        try {
            $verification = $this->verifier->releasePinHeld($pid, $nonce);
        } catch (Throwable) {
            throw new Unavailable(Unavailable::VERIFIER_UNAVAILABLE);
        }
        if (! $verification->held) {
            throw new Unavailable(Unavailable::NOT_PROVEN);
        }
        if ($verification->activeReleaseId !== $releaseId) {
            throw new RefException(RefException::RELEASE_CHANGED);
        }
    }
}
