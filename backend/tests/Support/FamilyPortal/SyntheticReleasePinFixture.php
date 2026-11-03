<?php

namespace Tests\Support\FamilyPortal;

use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin as Pin;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A synthetic stand-in for the registry side of the release pin (FB-FAMILY-ADD-01
 * ADD-WP-07B-2b), for PostgreSQL tests only. It is NOT identity_ref and changes nothing
 * there: one schema in the test database, created committed (other sessions must see it)
 * and dropped after each test. Under tests/ (autoload-dev), bound nowhere, and it refuses
 * to run outside the test runner.
 *
 * It models the three DBA proposals of ADD-WP-07B-2R, which do not exist in identity_ref:
 * - DBA-1, publish(): fails fast on 'FBCIV01A', then WAITS (bounded by lock_timeout) for
 *   the pin 'FBCIV01P' exclusively before switching the pointer;
 * - DBA-2, a pointer trigger: the release only moves forward, and only while the writer
 *   holds the pin exclusively;
 * - DBA-3, release_pin_held(pid, nonce): in one READ COMMITTED statement, whether the
 *   session `pid` holds the shared pin AND the nonce lock in one LIVE virtual transaction
 *   in THIS database, and is not the caller, with the active release. Its body is the
 *   proposed DBA-3 body (ADR §10a), minus SECURITY DEFINER and the grants.
 */
final class SyntheticReleasePinFixture
{
    public const SCHEMA = 'synthetic_release_pin';

    /** 'FBCIV01A': the publication lock publish_release() already takes. */
    public const PUBLICATION_KEY = 0x4642434956303141;

    public function __construct(private readonly string $schema = self::SCHEMA)
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The synthetic release pin fixture runs in tests only.');
        }
        if (preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $schema) !== 1) {
            throw new LogicException('Not a schema name.');
        }
    }

    public function schema(): string
    {
        return $this->schema;
    }

    /** A separate session on the test database (or $database), autocommit, never the default connection. */
    public static function connection(string $name, ?string $database = null): Connection
    {
        $connection = 'synthetic_pin_'.$name;
        $config = config('database.connections.pgsql');
        if ($database !== null) {
            $config['database'] = $database;
        }
        config(['database.connections.'.$connection => $config]);
        DB::purge($connection);

        return DB::connection($connection);
    }

    public function install(Connection $admin, int $activeRelease): void
    {
        $this->uninstall($admin);
        [$pinHigh, $pinLow] = self::split(Pin::PIN_KEY);
        $s = $this->schema;
        $admin->unprepared(<<<SQL
            CREATE SCHEMA {$s};
            CREATE TABLE {$s}.active_release (id smallint PRIMARY KEY CHECK (id = 1), release_id bigint NOT NULL CHECK (release_id >= 1));
            CREATE TABLE {$s}.probe_writes (id bigserial PRIMARY KEY, note text NOT NULL);
            INSERT INTO {$s}.active_release (id, release_id) VALUES (1, {$activeRelease});

            CREATE FUNCTION {$s}.pointer_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$f\$
            BEGIN
                IF NEW.release_id OPERATOR(pg_catalog.<=) OLD.release_id THEN
                    RAISE EXCEPTION 'ACTIVE_RELEASE_NOT_FORWARD' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_locks l
                     WHERE l.locktype OPERATOR(pg_catalog.=) 'advisory' AND l.pid OPERATOR(pg_catalog.=) pg_catalog.pg_backend_pid()
                       AND l.database OPERATOR(pg_catalog.=) (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname OPERATOR(pg_catalog.=) pg_catalog.current_database())
                       AND l.classid OPERATOR(pg_catalog.=) {$pinHigh}::pg_catalog.oid AND l.objid OPERATOR(pg_catalog.=) {$pinLow}::pg_catalog.oid
                       AND l.objsubid OPERATOR(pg_catalog.=) 1 AND l.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND l.granted) THEN
                    RAISE EXCEPTION 'ACTIVE_RELEASE_PIN_NOT_HELD' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$f\$;
            CREATE TRIGGER pointer_guard BEFORE UPDATE ON {$s}.active_release FOR EACH ROW EXECUTE FUNCTION {$s}.pointer_guard();

            CREATE FUNCTION {$s}.publish(p_release bigint, p_wait_ms integer) RETURNS void LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$f\$
            BEGIN
                IF NOT pg_catalog.pg_try_advisory_xact_lock(x'4642434956303141'::bigint) THEN
                    RAISE EXCEPTION 'PUBLICATION_IN_PROGRESS' USING ERRCODE = 'lock_not_available';
                END IF;
                PERFORM pg_catalog.set_config('lock_timeout', p_wait_ms::text || 'ms', true);
                PERFORM pg_catalog.pg_advisory_xact_lock(x'4642434956303150'::bigint);
                UPDATE {$s}.active_release SET release_id = p_release WHERE id = 1;
            END \$f\$;

            CREATE FUNCTION {$s}.release_pin_held(p_holder_pid integer, p_nonce integer)
                RETURNS TABLE (held boolean, active_release_id bigint)
                LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp AS \$f\$
                SELECT EXISTS (
                           SELECT 1 FROM pg_catalog.pg_locks pin JOIN pg_catalog.pg_locks nonce
                             ON nonce.pid OPERATOR(pg_catalog.=) pin.pid AND nonce.virtualtransaction OPERATOR(pg_catalog.=) pin.virtualtransaction
                            WHERE pin.locktype OPERATOR(pg_catalog.=) 'advisory' AND nonce.locktype OPERATOR(pg_catalog.=) 'advisory'
                              AND pin.database OPERATOR(pg_catalog.=) (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname OPERATOR(pg_catalog.=) pg_catalog.current_database())
                              AND nonce.database OPERATOR(pg_catalog.=) pin.database
                              AND pin.classid OPERATOR(pg_catalog.=) {$pinHigh}::pg_catalog.oid AND pin.objid OPERATOR(pg_catalog.=) {$pinLow}::pg_catalog.oid
                              AND pin.objsubid OPERATOR(pg_catalog.=) 1 AND pin.mode OPERATOR(pg_catalog.=) 'ShareLock' AND pin.granted
                              AND nonce.classid OPERATOR(pg_catalog.=) {$this->nonceNamespace()}::pg_catalog.oid AND nonce.objid OPERATOR(pg_catalog.=) p_nonce::pg_catalog.oid
                              AND nonce.objsubid OPERATOR(pg_catalog.=) 2 AND nonce.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND nonce.granted
                              AND pin.pid OPERATOR(pg_catalog.=) p_holder_pid AND pin.pid OPERATOR(pg_catalog.<>) pg_catalog.pg_backend_pid()
                              AND p_nonce OPERATOR(pg_catalog.>) 0
                              -- The holder is INSIDE a live transaction with that virtual id: an idle
                              -- session's session-level locks are not a pin.
                              AND EXISTS (SELECT 1 FROM pg_catalog.pg_locks live
                                   WHERE live.locktype OPERATOR(pg_catalog.=) 'virtualxid' AND live.pid OPERATOR(pg_catalog.=) pin.pid
                                     AND live.virtualxid OPERATOR(pg_catalog.=) pin.virtualtransaction
                                     AND live.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND live.granted))
                       -- A fresh snapshot only: in an older (REPEATABLE READ / SERIALIZABLE) snapshot the
                       -- active release below could be stale, so nothing is proven there.
                       AND pg_catalog.current_setting('transaction_isolation') OPERATOR(pg_catalog.=) 'read committed',
                       (SELECT a.release_id FROM {$s}.active_release a WHERE a.id OPERATOR(pg_catalog.=) 1)
            \$f\$;
        SQL);
    }

    public function uninstall(Connection $admin): void
    {
        $admin->unprepared("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }

    public function activeRelease(Connection $any): int
    {
        return (int) $any->scalar("SELECT release_id FROM {$this->schema}.active_release WHERE id = 1");
    }

    /** Publication in the caller's own short transaction; a failure leaves the pointer unchanged. */
    public function publish(Connection $publisher, int $release, int $waitMs): void
    {
        $publisher->transaction(fn () => $publisher->select("SELECT {$this->schema}.publish(?, ?)", [$release, $waitMs]));
    }

    /** [classid, objid] of a single-bigint advisory key, as pg_locks shows it. */
    public static function split(int $key): array
    {
        return [($key >> 32) & 0xFFFFFFFF, $key & 0xFFFFFFFF];
    }

    private function nonceNamespace(): int
    {
        return Pin::NONCE_NAMESPACE;
    }
}
