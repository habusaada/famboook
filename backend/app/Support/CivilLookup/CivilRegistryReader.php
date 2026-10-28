<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Support\IdentityRef\IdentityRefErrorRedactor;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The ONLY user of the `identity_ref_lookup` connection (FB-CIVIL-02A, ADR §L-2):
 * - the login and password come at run time from the protected file
 *   config('civil_lookup.credentials_file') — never .env, never the cached
 *   configuration;
 * - the session runs SET ROLE famboook_identity_reader and refuses a superuser
 *   login, one that can act as the identity owner, writer or monitor, and
 *   silent reconnects (which would drop SET ROLE);
 * - it calls the identity_ref_lookup functions only; the reader holds EXECUTE
 *   on them and nothing else, and the functions read the active release only.
 *
 * Database errors are logged redacted (SQLSTATE / constraint) and answered as
 * LOOKUP_UNAVAILABLE; no payload, National ID or term ever reaches the log.
 */
final class CivilRegistryReader implements CivilRegistrySource
{
    public const CONNECTION = 'identity_ref_lookup';

    public const ROLE = 'famboook_identity_reader';

    private ?Connection $db = null;

    public function activeReleaseId(): ?int
    {
        $id = $this->call(fn (Connection $db) => $db->selectOne('SELECT identity_ref_lookup.active_release_id() AS id')?->id);

        return $id === null ? null : (int) $id;
    }

    public function byMainid(string $mainid): ?CivilRecord
    {
        $row = $this->call(fn (Connection $db) => $db->selectOne('SELECT * FROM identity_ref_lookup.by_mainid(?)', [$mainid]));

        return $row === null ? null : self::record($row);
    }

    public function byRecord(int $recordId): ?CivilRecord
    {
        $row = $this->call(fn (Connection $db) => $db->selectOne('SELECT * FROM identity_ref_lookup.by_record(?)', [$recordId]));

        return $row === null ? null : self::record($row);
    }

    public function existingMainids(array $mainids): array
    {
        if ($mainids === []) {
            return ['release_id' => $this->activeReleaseId(), 'mainids' => []];
        }
        $rows = $this->call(fn (Connection $db) => $db->select('SELECT * FROM identity_ref_lookup.existing_mainids(?::text[])', [self::textArray($mainids)]));

        return [
            'release_id' => $rows === [] ? $this->activeReleaseId() : (int) $rows[0]->release_id,
            'mainids' => array_values(array_map(fn (object $r) => (string) $r->mainid, $rows)),
        ];
    }

    public function searchFullname(array $tokens, int $offset, int $limit): CivilRecordPage
    {
        return $this->page(self::SEARCH_CAP, fn (Connection $db) => $db->select('SELECT * FROM identity_ref_lookup.search_fullname(?::text[], ?, ?)',
            [self::textArray($tokens), $offset, $limit]));
    }

    public function searchParts(array $parts, int $offset, int $limit): CivilRecordPage
    {
        return $this->page(self::SEARCH_CAP, fn (Connection $db) => $db->select('SELECT * FROM identity_ref_lookup.search_parts(?, ?, ?, ?, ?, ?)',
            [$parts['name1'], $parts['father'], $parts['grandfath'], $parts['family'], $offset, $limit]));
    }

    public function householdMembers(string $householdId, string $excludeMainid, int $offset, int $limit): CivilRecordPage
    {
        return $this->page(self::LINKS_CAP, fn (Connection $db) => $db->select('SELECT * FROM identity_ref_lookup.household_members(?, ?, ?, ?)',
            [$householdId, $excludeMainid, $offset, $limit]));
    }

    public function children(string $mainid, int $offset, int $limit): CivilRecordPage
    {
        return $this->page(self::LINKS_CAP, fn (Connection $db) => $db->select('SELECT * FROM identity_ref_lookup.children(?, ?, ?)',
            [$mainid, $offset, $limit]));
    }

    // ---------------------------------------------------------------- internals

    /** The meta row (record_id NULL) carries the capped total; the page rows follow (none when too broad). */
    private function page(int $cap, \Closure $query): CivilRecordPage
    {
        $rows = $this->call($query);
        $meta = null;
        $records = [];
        foreach ($rows as $row) {
            if ($row->record_id === null) {
                $meta = $row;
            } else {
                $records[] = self::record($row);
            }
        }

        return new CivilRecordPage($meta?->release_id === null ? null : (int) $meta->release_id, (int) ($meta->total ?? 0), $records, $cap);
    }

    private static function record(object $row): CivilRecord
    {
        $payload = json_decode((string) $row->source_payload, true);

        return new CivilRecord((int) $row->release_id, (int) $row->record_id, (string) $row->mainid, is_array($payload) ? $payload : []);
    }

    /** @param  list<string>  $values */
    private static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v) => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }

    /**
     * @template T
     *
     * @param  \Closure(Connection): T  $query
     * @return T
     */
    private function call(\Closure $query): mixed
    {
        $db = $this->db();
        try {
            return $query($db);
        } catch (Throwable $e) {
            Log::warning('civil_lookup.registry.read_failed', ['error' => IdentityRefErrorRedactor::redact($e)]);
            throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
        }
    }

    /** The lookup session, opened once per request. */
    private function db(): Connection
    {
        if ($this->db !== null) {
            return $this->db;
        }
        if (config('database.connections.'.self::CONNECTION.'.username') === null) {
            $this->loadCredentials();
        }
        try {
            $db = DB::connection(self::CONNECTION);
            $db->setReconnector(static function (): void {
                throw new RuntimeException('IDENTITY_REF_LOOKUP_CONNECTION_LOST');
            });
            $db->unprepared('SET ROLE '.self::ROLE);
            // By oid: a role that is not installed (e.g. the monitor) counts as "not a member", never as an error.
            $member = fn (string $role) => "coalesce((SELECT pg_has_role(session_user, g.oid, 'MEMBER') FROM pg_roles g WHERE g.rolname = '{$role}'), false)";
            $me = $db->selectOne("SELECT current_user AS role, s.rolsuper AS su,
                    {$member('famboook_identity_writer')} AS writer,
                    {$member('famboook_identity_owner')} AS owner,
                    {$member('famboook_identity_monitor')} AS monitor
                FROM pg_roles s WHERE s.rolname = session_user");
        } catch (Throwable $e) {
            Log::warning('civil_lookup.registry.unavailable', ['error' => IdentityRefErrorRedactor::redact($e)]);
            throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
        }
        if ($me === null || $me->role !== self::ROLE || $me->su || $me->writer || $me->owner || $me->monitor) {
            DB::purge(self::CONNECTION);
            Log::warning('civil_lookup.registry.refused_login', ['reason' => 'LOGIN_NOT_READ_ONLY']);
            throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
        }

        return $this->db = $db;
    }

    /** Credentials from the protected file; set at run time only. */
    private function loadCredentials(): void
    {
        $path = (string) config('civil_lookup.credentials_file');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new CivilLookupException(CivilLookupException::NOT_CONFIGURED);
        }
        $credentials = (static fn (string $file) => require $file)($path);
        if (! is_array($credentials) || ! is_string($credentials['username'] ?? null) || ! is_string($credentials['password'] ?? null)
            || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $credentials['username']) !== 1) {
            throw new CivilLookupException(CivilLookupException::NOT_CONFIGURED);
        }
        config([
            'database.connections.'.self::CONNECTION.'.username' => $credentials['username'],
            'database.connections.'.self::CONNECTION.'.password' => $credentials['password'],
        ]);
        DB::purge(self::CONNECTION);
    }
}
