<?php

namespace App\Support\IdentityRef;

use App\Exceptions\IdentityImportException;
use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * The database session every identity import step runs in (FB-CIVIL-01A
 * P2/P3): the connection acts as famboook_identity_writer (SET ROLE; refused
 * for a non-member, a superuser role or a database other than the named
 * target), never reconnects silently, and holds the import advisory lock —
 * one staging, preparation or publication process at a time.
 */
final class IdentityImportSession
{
    public const WRITER_ROLE = 'famboook_identity_writer';

    /** Session advisory-lock key ("FBCIV01S" in ASCII); publish_release() itself uses "FBCIV01A". */
    public const LOCK_KEY = 0x4642434956303153;

    /**
     * Runs $fn as the writer while holding the import lock.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     *
     * @throws IdentityImportException
     */
    public static function run(Connection $db, string $target, Closure $fn): mixed
    {
        self::actAsWriter($db, $target);
        if (! $db->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [self::LOCK_KEY])->ok) {
            throw new IdentityImportException(IdentityImportException::CONCURRENT_IMPORT, 'Another identity import process is running.');
        }
        try {
            return $fn();
        } finally {
            try {
                $db->selectOne('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
            } catch (Throwable) {
                // The session is gone; PostgreSQL released its lock with it.
            }
        }
    }

    /**
     * Runs a read-only $fn as the writer WITHOUT taking the import lock
     * (status inspection while another process works).
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function readOnly(Connection $db, string $target, Closure $fn): mixed
    {
        self::actAsWriter($db, $target);

        return $fn();
    }

    private static function actAsWriter(Connection $db, string $target): void
    {
        if ($db->getDriverName() !== 'pgsql') {
            throw new IdentityImportException(IdentityImportException::NOT_CONFIGURED, 'The import connection must be PostgreSQL.');
        }
        if ($target === '') {
            throw new IdentityImportException(IdentityImportException::UNSAFE_TARGET, 'The target database must be named explicitly.');
        }
        // A dropped session must surface as an interruption, never reconnect (and lose SET ROLE).
        $db->setReconnector(static function (): void {
            throw new RuntimeException('IDENTITY_REF_CONNECTION_LOST');
        });
        try {
            $db->unprepared('SET ROLE '.self::WRITER_ROLE);
        } catch (Throwable) {
            throw new IdentityImportException(IdentityImportException::WRONG_ROLE, 'This login cannot act as '.self::WRITER_ROLE.'.');
        }
        $me = $db->selectOne('SELECT current_user AS role, current_database() AS db, r.rolsuper AS su
            FROM pg_roles r WHERE r.rolname = current_user');
        if ($me === null || $me->role !== self::WRITER_ROLE || $me->su) {
            throw new IdentityImportException(IdentityImportException::WRONG_ROLE, 'The session does not run as a non-superuser '.self::WRITER_ROLE.'.');
        }
        if ($me->db !== $target) {
            throw new IdentityImportException(IdentityImportException::UNSAFE_TARGET, 'The connected database is not the named target.');
        }
    }
}
