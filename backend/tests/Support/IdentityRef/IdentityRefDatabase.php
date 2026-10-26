<?php

namespace Tests\Support\IdentityRef;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * FB-CIVIL-01A-P1 PostgreSQL harness for the identity reference schema.
 *
 * Applies deploy/sql/identity-ref/{00-roles.dba,01-schema}.sql to the
 * disposable *_test database (TestDatabaseGuard has already refused any other
 * database; this trait additionally refuses port 5432), exactly as a DBA
 * would: roles as the administrator, the schema AS famboook_identity_owner.
 * Every test then runs its statements under a REAL PostgreSQL role (SET ROLE),
 * so privilege results are PostgreSQL's own, not simulated. The schema is
 * dropped after each test with the guarded drop script. Roles are cluster
 * objects of the disposable test cluster and are left in place.
 *
 * SYNTHETIC DATA ONLY.
 */
trait IdentityRefDatabase
{
    protected const OWNER = 'famboook_identity_owner';

    protected const WRITER = 'famboook_identity_writer';

    protected const READER = 'famboook_identity_reader';

    protected const APP = 'famboook_app';

    /** @var list<PDO> */
    private array $extraConnections = [];

    /**
     * $withPublication also applies 03-publication.sql (P3). P1 and P2 tests
     * exercise their own primitives WITHOUT the P3 publication gate (which
     * deliberately refuses any release that was not prepared and verified);
     * P3 tests run on the complete 00–03 set. $withMonitor (FB-CIVIL-01A-UI
     * U1) additionally applies 06-monitor.dba.sql, as the administrator.
     */
    protected function setUpIdentityRef(bool $withPublication = false, bool $withMonitor = false): void
    {
        $withPublication = $withPublication || $withMonitor;
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against the disposable *_test cluster.');
        }
        $target = DB::selectOne('SELECT current_database() AS db, inet_server_port() AS port, rolsuper AS su FROM pg_roles WHERE rolname = current_user');
        if (! str_ends_with((string) $target->db, '_test') || (int) $target->port === 5432) {
            throw new RuntimeException('REFUSING_IDENTITY_REF_TESTS_OUTSIDE_A_DISPOSABLE_TEST_DATABASE');
        }
        if (! $target->su) {
            $this->markTestSkipped('Needs the disposable cluster administrator to create roles and SET ROLE.');
        }

        DB::unprepared('RESET ROLE');
        DB::unprepared($this->sqlFile('90-drop.local-test-only.sql'));
        DB::unprepared($this->sqlFile('00-roles.dba.sql'));
        // The application role, as it exists in Production (no privilege on identity_ref).
        DB::unprepared("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_app') THEN CREATE ROLE famboook_app NOLOGIN; END IF; END \$\$");
        DB::unprepared('SET ROLE '.self::OWNER);
        try {
            DB::unprepared($this->sqlFile('01-schema.sql'));
            DB::unprepared($this->sqlFile('02-staging.sql'));
            if ($withPublication) {
                DB::unprepared($this->sqlFile('03-publication.sql'));
            }
        } finally {
            DB::unprepared('RESET ROLE');
        }
        if ($withMonitor) {
            DB::unprepared($this->sqlFile('06-monitor.dba.sql'));
        }
    }

    protected function tearDownIdentityRef(): void
    {
        foreach ($this->extraConnections as $pdo) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
            }
        }
        $this->extraConnections = [];
        if (DB::connection()->getDriverName() === 'pgsql') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::unprepared('RESET ROLE');
            DB::unprepared($this->sqlFile('90-drop.local-test-only.sql'));
        }
    }

    protected function sqlFile(string $name): string
    {
        $path = base_path('../deploy/sql/identity-ref/'.$name);
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Missing SQL file '.$name);
        }

        return $sql;
    }

    /** Runs $fn with the default connection switched to a real PostgreSQL role. */
    protected function asRole(string $role, Closure $fn): mixed
    {
        DB::unprepared('SET ROLE '.$role);
        try {
            return $fn();
        } finally {
            DB::unprepared('RESET ROLE');
        }
    }

    /** A separate session (own transaction, own snapshot), optionally under a role. */
    protected function connection(?string $role = null): PDO
    {
        $c = DB::connection()->getConfig();
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        if ($role !== null) {
            $pdo->exec('SET ROLE '.$role);
        }
        $this->extraConnections[] = $pdo;

        return $pdo;
    }

    /** SQLSTATE of the exception thrown by $fn, or null when it succeeds. */
    protected function sqlStateOf(Closure $fn): ?string
    {
        try {
            $fn();

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        } catch (\PDOException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    // ------------------------------------------------------------ synthetic fixtures (writer path)

    /** @return array<string, string|null> the 36 source fields, synthetic values only */
    protected function payload(string $mainid, array $overrides = []): array
    {
        $fields = array_fill_keys([
            'sert1', 'mainid', 'name1', 'father', 'grandfath', 'family', 'gender', 'govern', 'city', 'street',
            'fatherid', 'motherid', 'partnerid', 'datebirth', 'age', 'maritalst', 'deathdate', 'presonstate',
            'relgion', 'birthplace', 'householdid', 'householdrel', 'prevfamily', 'phonenum', 'worker', 'att',
            'worktype', 'source', 'civilg', 'cwloc', 'mgrade', 'mstartw', 'msalary', 'select', 'job', 'fullname',
        ], '');
        $fields = array_merge($fields, [
            'sert1' => '7'.substr(str_pad($mainid, 6, '0', STR_PAD_LEFT), -6),
            'mainid' => $mainid,
            'name1' => 'اسم-تجريبي',
            'family' => 'عائلة-تجريبية',
            'gender' => '1',
            'datebirth' => '1990-01-15T00:00:00',
            'worker' => '0',
            'select' => '0',
            'fullname' => 'اسم-تجريبي عائلة-تجريبية',
        ], $overrides);

        return $fields;
    }

    protected function startRun(): string
    {
        return $this->asRole(self::WRITER, fn () => DB::selectOne(
            "INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows) VALUES (?, 't03maindata', 3) RETURNING id",
            [str_repeat('a', 64)],
        )->id);
    }

    protected function finishRun(string $runId, string $status = 'SUCCEEDED'): void
    {
        $this->asRole(self::WRITER, fn () => DB::update(
            'UPDATE identity_ref.import_runs SET status = ?, finished_at = now() WHERE id = ?', [$status, $runId]));
    }

    protected function newRelease(string $runId, ?int $baseReleaseId): int
    {
        return (int) $this->asRole(self::WRITER, fn () => DB::selectOne(
            'INSERT INTO identity_ref.dataset_releases (run_id, base_release_id) VALUES (?, ?) RETURNING id', [$runId, $baseReleaseId],
        )->id);
    }

    /** The writer's per-identity primitive: anchor, deduplicated version, presence. Returns the version id. */
    protected function present(string $runId, int $releaseId, array $payload): int
    {
        return (int) $this->asRole(self::WRITER, function () use ($runId, $releaseId, $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            DB::insert('INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES (?, ?) ON CONFLICT (mainid) DO NOTHING',
                [$payload['mainid'], $runId]);
            $recordId = DB::selectOne('SELECT id FROM identity_ref.records WHERE mainid = ?', [$payload['mainid']])->id;
            // version_no and content_sha256 are placeholders: the BEFORE INSERT trigger computes both.
            DB::insert("INSERT INTO identity_ref.record_versions (record_id, version_no, content_sha256, source_payload, created_run_id)
                        VALUES (?, 1, decode(?, 'hex'), ?::jsonb, ?) ON CONFLICT (record_id, content_sha256) DO NOTHING",
                [$recordId, str_repeat('00', 32), $json, $runId]);
            $versionId = DB::selectOne("SELECT id FROM identity_ref.record_versions
                        WHERE record_id = ? AND content_sha256 = sha256(convert_to(?::jsonb::text, 'UTF8'))", [$recordId, $json])->id;
            DB::insert("INSERT INTO identity_ref.release_presence (release_id, record_id, record_version_id, source_state)
                        VALUES (?, ?, ?, 'PRESENT')", [$releaseId, $recordId, $versionId]);

            return $versionId;
        });
    }

    protected function publish(int $releaseId): int
    {
        return (int) $this->asRole(self::WRITER, fn () => DB::selectOne('SELECT identity_ref.publish_release(?) AS n', [$releaseId])->n);
    }

    /** A published release containing the given identities (mainid => payload overrides). */
    protected function publishedRelease(array $identities, ?int $base = null): int
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, $base);
        foreach ($identities as $mainid => $overrides) {
            $this->present($run, $release, $this->payload((string) $mainid, $overrides));
        }
        if ($base !== null) {
            $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.carry_forward_missing(?)', [$release]));
        }
        $this->publish($release);
        $this->finishRun($run);

        return $release;
    }

    /** @return array<string, string> mainid => source_state of the ACTIVE release (read as the owner). */
    protected function activeIdentities(): array
    {
        return $this->asRole(self::OWNER, fn () => collect(DB::select('SELECT mainid, source_state FROM identity_ref.active_records ORDER BY mainid'))
            ->mapWithKeys(fn ($r) => [$r->mainid => $r->source_state])->all());
    }
}
