<?php

namespace App\Actions;

use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportSession;
use App\Support\IdentityRef\IdentityManifest;
use App\Support\IdentityRef\IdentityManifestException;
use App\Support\IdentityRef\IdentityRefErrorRedactor;
use App\Support\IdentityRef\IdentitySourceChangedException;
use App\Support\IdentityRef\IdentitySourceVerifier;
use App\Support\IdentityRef\IdentityStagingLoader;
use Closure;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Validates an identity export directory and stages it into
 * identity_ref_staging (FB-CIVIL-01A-P2). It NEVER publishes: no record,
 * version, release or active-release row is written (P3).
 *
 * Lifecycle:
 * 1. The connection acts as famboook_identity_writer (SET ROLE; refused for
 *    a non-member, a superuser role or a database other than the named
 *    target). Reconnecting is disabled, so a lost session can never silently
 *    continue under another identity.
 * 2. One staging process at a time: session advisory lock + the single
 *    RUNNING run index.
 * 3. The manifest and directory inventory are validated (no run recorded on
 *    failure). A RUNNING run with the same manifest is RESUMED; one with a
 *    different manifest is refused (abort it first).
 * 4. Every file is fully re-validated (BOM, header, structure, row count,
 *    SHA-256) — also on resume. A failure records issues, marks the run
 *    REJECTED and purges its staging.
 * 5. Files are loaded chunk by chunk (IdentityStagingLoader). A source file
 *    that changes while staging → REJECTED and purged.
 * 6. The last file is marked STAGED in the same transaction that records
 *    the key-level issues (missing, duplicate, non-9-digit, surrounding
 *    space): every file STAGED ⇔ analysis done. The run stays RUNNING,
 *    awaiting P3.
 * Any unexpected error leaves the run RUNNING (resumable) and is rethrown as
 * STAGING_INTERRUPTED with a redacted message only.
 *
 * Logged and returned: run id, safe file names, counts and codes — never a
 * value from the export.
 */
final class StageIdentityImportAction
{
    public const WRITER_ROLE = IdentityImportSession::WRITER_ROLE;

    /** Session advisory-lock key, shared by staging, preparation and publication. */
    public const LOCK_KEY = IdentityImportSession::LOCK_KEY;

    public const DEFAULT_CHUNK_SIZE = 5000;

    public function __construct(private readonly IdentitySourceVerifier $verifier) {}

    /**
     * @param  array{target: string, expected_files?: ?int, expected_rows?: ?int, chunk_size?: int}  $options
     * @param  ?Closure(string, array<string, mixed>): void  $hook  test seam (see IdentityStagingLoader)
     * @return array{run_id: string, resumed: bool, status: string, files: int, files_staged_now: int, files_reset: int,
     *               chunks_verified: int, chunks_loaded: int, rows_loaded_now: int, rows_staged: int, issues: array<string, int>}
     *
     * @throws IdentityImportException
     */
    public function handle(Connection $db, string $directory, array $options, ?LoggerInterface $logger = null, ?Closure $hook = null): array
    {
        $logger ??= new NullLogger;
        $hook ??= static function (): void {};
        $chunkSize = max(1, (int) ($options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE));

        return IdentityImportSession::run($db, (string) ($options['target'] ?? ''), function () use ($db, $directory, $options, $logger, $hook, $chunkSize) {
            return $this->stage($db, $directory, $options, $logger, $hook, $chunkSize);
        });
    }

    /**
     * @param  Closure(string, array<string, mixed>): void  $hook
     * @return array<string, mixed>
     */
    private function stage(Connection $db, string $directory, array $options, LoggerInterface $logger, Closure $hook, int $chunkSize): array
    {
        $runId = null;
        try {
            try {
                $manifest = IdentityManifest::fromDirectory($directory, $options['expected_files'] ?? null, $options['expected_rows'] ?? null);
            } catch (IdentityManifestException $e) {
                $logger->warning('identity_ref.stage.manifest_refused', ['problems' => $e->problems]);
                throw new IdentityImportException(IdentityImportException::MANIFEST_INVALID, 'The manifest or directory inventory is invalid; nothing was recorded.', $e->problems);
            }

            [$runId, $resumed] = $this->openRun($db, $manifest);
            $logger->info('identity_ref.stage.run_opened', ['run_id' => $runId, 'resumed' => $resumed, 'files' => count($manifest->files)]);

            $problems = $this->verifier->verify($manifest);
            if ($problems !== []) {
                $this->reject($db, $runId, 'SOURCE_INVALID', $problems);
                $logger->warning('identity_ref.stage.source_rejected', ['run_id' => $runId, 'problems' => self::describe($problems)]);
                $this->fail(IdentityImportException::SOURCE_INVALID, 'Source validation failed; the run is REJECTED and its staging purged.', self::describe($problems), $runId);
            }
            $db->update("UPDATE identity_ref.import_files SET status = 'VERIFIED', actual_sha256 = expected_sha256
                WHERE run_id = ? AND status = 'PENDING'", [$runId]);

            $report = $this->stageFiles($db, $manifest, $runId, $chunkSize, $hook, $logger);
            $logger->info('identity_ref.stage.completed', ['run_id' => $runId] + $report);

            return ['run_id' => $runId, 'resumed' => $resumed, 'status' => 'STAGED_AWAITING_PUBLICATION', 'files' => count($manifest->files)] + $report;
        } catch (IdentityImportException $e) {
            $e->runId ??= $runId;
            throw $e;
        } catch (Throwable $e) {
            $redacted = IdentityRefErrorRedactor::redact($e);
            $logger->error('identity_ref.stage.interrupted', ['run_id' => $runId, 'error' => $redacted]);
            $this->fail(IdentityImportException::STAGING_INTERRUPTED, 'Staging stopped; the run stays RUNNING and can be resumed. '.$redacted, [], $runId);
        }
    }

    /**
     * Closes a RUNNING run as FAILED (ABORTED) — abandoning its BUILDING release,
     * if P3 prepared one — and purges the staging of every finished run. Completed staging of a RUNNING run is never touched
     * except by aborting that run.
     *
     * @return array{aborted_run: ?string, abandoned_release: ?int, rows_purged: int}
     *
     * @throws IdentityImportException
     */
    public function abort(Connection $db, string $target, ?LoggerInterface $logger = null): array
    {
        $logger ??= new NullLogger;

        return IdentityImportSession::run($db, $target, function () use ($db, $logger) {
            try {
                $result = $db->transaction(function () use ($db) {
                    $running = $db->selectOne("SELECT id FROM identity_ref.import_runs WHERE status = 'RUNNING'");
                    $abandoned = null;
                    if ($running !== null) {
                        // A BUILDING release of the run (P3 preparation) can never be activated afterwards.
                        $abandoned = $db->selectOne("SELECT id FROM identity_ref.dataset_releases WHERE run_id = ? AND status = 'BUILDING'", [$running->id])?->id;
                        if ($abandoned !== null) {
                            $db->select('SELECT identity_ref.abandon_release(?)', [$abandoned]);
                        }
                        $db->update("UPDATE identity_ref.import_runs SET status = 'FAILED', finished_at = now(), error_code = 'ABORTED' WHERE id = ?", [$running->id]);
                    }

                    return ['aborted_run' => $running?->id, 'abandoned_release' => $abandoned === null ? null : (int) $abandoned,
                        'rows_purged' => self::purgeFinishedRuns($db)];
                });
                $logger->info('identity_ref.stage.aborted', $result);

                return $result;
            } catch (Throwable $e) {
                $redacted = IdentityRefErrorRedactor::redact($e);
                $logger->error('identity_ref.stage.abort_failed', ['error' => $redacted]);
                $this->fail(IdentityImportException::STAGING_INTERRUPTED, 'Abort failed. '.$redacted, [], null);
            }
        });
    }

    /** @return array{0: string, 1: bool} */
    private function openRun(Connection $db, IdentityManifest $manifest): array
    {
        $running = $db->selectOne("SELECT id, manifest_sha256 FROM identity_ref.import_runs WHERE status = 'RUNNING'");
        if ($running !== null) {
            $files = $db->select('SELECT file_name, expected_sha256, expected_rows FROM identity_ref.import_files WHERE run_id = ? ORDER BY file_name', [$running->id]);
            $expected = collect($manifest->files)->sortBy('file')->map(fn ($f) => [$f['file'], $f['sha256'], $f['rows']])->values()->all();
            $actual = array_map(fn ($f) => [$f->file_name, $f->expected_sha256, (int) $f->expected_rows], $files);
            if ($running->manifest_sha256 !== $manifest->sha256 || $actual !== $expected) {
                $this->fail(IdentityImportException::RUN_CONFLICT, 'A different import run is RUNNING; abort it first.', [], $running->id);
            }

            return [$running->id, true];
        }

        $runId = $db->transaction(function () use ($db, $manifest) {
            $runId = $db->selectOne('INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows) VALUES (?, ?, ?) RETURNING id',
                [$manifest->sha256, $manifest->table, $manifest->totalRows])->id;
            foreach ($manifest->files as $f) {
                $db->insert('INSERT INTO identity_ref.import_files (run_id, file_name, expected_sha256, expected_rows) VALUES (?, ?, ?, ?)',
                    [$runId, $f['file'], $f['sha256'], $f['rows']]);
            }

            return $runId;
        });

        return [$runId, false];
    }

    /**
     * @param  Closure(string, array<string, mixed>): void  $hook
     * @return array{files_staged_now: int, files_reset: int, chunks_verified: int, chunks_loaded: int, rows_loaded_now: int, rows_staged: int, issues: array<string, int>}
     */
    private function stageFiles(Connection $db, IdentityManifest $manifest, string $runId, int $chunkSize, Closure $hook, LoggerInterface $logger): array
    {
        $loader = new IdentityStagingLoader($db, $chunkSize, $hook);
        $totals = ['files_staged_now' => 0, 'files_reset' => 0, 'chunks_verified' => 0, 'chunks_loaded' => 0, 'rows_loaded_now' => 0];
        $pending = array_map(fn ($f) => (array) $f, $db->select("SELECT id, run_id, file_name, expected_rows, expected_sha256, loaded_rows
            FROM identity_ref.import_files WHERE run_id = ? AND status = 'VERIFIED' ORDER BY file_name", [$runId]));

        foreach ($pending as $i => $file) {
            $file['id'] = (int) $file['id'];
            $file['expected_rows'] = (int) $file['expected_rows'];
            $file['loaded_rows'] = (int) $file['loaded_rows'];
            try {
                $result = $loader->load($manifest->path($file['file_name']), $file);
            } catch (IdentitySourceChangedException $e) {
                $problem = [['file' => $e->fileName, 'code' => 'SOURCE_CHANGED', 'line' => null]];
                $this->reject($db, $runId, 'SOURCE_CHANGED', $problem);
                $logger->warning('identity_ref.stage.source_changed', ['run_id' => $runId, 'file' => $e->fileName]);
                $this->fail(IdentityImportException::SOURCE_CHANGED, 'A source file changed while staging; the run is REJECTED and its staging purged.', self::describe($problem), $runId);
            }
            $totals['files_reset'] += $result['reset'] ? 1 : 0;
            $totals['chunks_verified'] += $result['chunks_verified'];
            $totals['chunks_loaded'] += $result['chunks_loaded'];
            $totals['rows_loaded_now'] += $result['rows_loaded'];
            $last = $i === array_key_last($pending);
            $db->transaction(function () use ($db, $file, $runId, $last) {
                $db->update("UPDATE identity_ref.import_files SET status = 'STAGED' WHERE id = ?", [$file['id']]);
                if ($last) {
                    $this->recordKeyIssues($db, $runId);
                }
            });
            $totals['files_staged_now']++;
            $logger->info('identity_ref.stage.file_staged', ['run_id' => $runId, 'file' => $file['file_name']] + $result);
        }

        $issues = collect($db->select('SELECT code, count(*) AS n FROM identity_ref.import_issues WHERE run_id = ? GROUP BY code ORDER BY code', [$runId]))
            ->mapWithKeys(fn ($r) => [$r->code => (int) $r->n])->all();
        $staged = (int) $db->selectOne('SELECT rows_loaded FROM identity_ref.import_runs WHERE id = ?', [$runId])->rows_loaded;

        return $totals + ['rows_staged' => $staged, 'issues' => $issues];
    }

    /**
     * Key-level findings over the whole staged run, as value-free issues
     * (file, line, code, column). Nothing is excluded or changed here: P3
     * decides what a finding means for publication.
     */
    private function recordKeyIssues(Connection $db, string $runId): void
    {
        $db->insert("WITH s AS (
                SELECT f.file_name, r.line_no, r.mainid FROM identity_ref_staging.rows r
                  JOIN identity_ref.import_files f ON f.id = r.file_id WHERE f.run_id = ?
            ), dup AS (
                SELECT mainid FROM s WHERE mainid IS NOT NULL AND mainid <> '' GROUP BY mainid HAVING count(*) > 1
            )
            INSERT INTO identity_ref.import_issues (run_id, file_name, line_number, code, column_name)
            SELECT ?, s.file_name, s.line_no, x.code, 'mainid'
              FROM s CROSS JOIN LATERAL (VALUES
                    (CASE WHEN s.mainid IS NULL OR s.mainid = '' THEN 'MAINID_MISSING' END),
                    (CASE WHEN s.mainid IN (SELECT mainid FROM dup) THEN 'MAINID_DUPLICATE' END),
                    (CASE WHEN s.mainid <> '' AND s.mainid !~ '^[0-9]{9}$' THEN 'MAINID_NOT_9_DIGITS' END),
                    (CASE WHEN s.mainid ~ '^\\s|\\s$' THEN 'MAINID_SURROUNDING_SPACE' END),
                    (CASE WHEN length(s.mainid) > 64 THEN 'MAINID_TOO_LONG' END)
                ) AS x(code)
             WHERE x.code IS NOT NULL
             ORDER BY s.file_name, s.line_no", [$runId, $runId]);
        $db->update('UPDATE identity_ref.import_runs SET issues = (SELECT count(*) FROM identity_ref.import_issues WHERE run_id = ?) WHERE id = ?', [$runId, $runId]);
    }

    /**
     * Records the problems, fails their files, closes the run REJECTED and
     * purges its staging — one transaction.
     *
     * @param  list<array{file: string, code: string, line: ?int}>  $problems
     */
    private function reject(Connection $db, string $runId, string $errorCode, array $problems): void
    {
        $db->transaction(function () use ($db, $runId, $errorCode, $problems) {
            foreach ($problems as $p) {
                $db->insert('INSERT INTO identity_ref.import_issues (run_id, file_name, line_number, code) VALUES (?, ?, ?, ?)',
                    [$runId, $p['file'], $p['line'], $p['code']]);
                $db->update("UPDATE identity_ref.import_files SET status = 'FAILED' WHERE run_id = ? AND file_name = ?", [$runId, $p['file']]);
            }
            $db->update("UPDATE identity_ref.import_runs SET status = 'REJECTED', finished_at = now(), error_code = ?,
                issues = (SELECT count(*) FROM identity_ref.import_issues WHERE run_id = ?) WHERE id = ?", [$errorCode, $runId, $runId]);
            self::purgeFinishedRuns($db);
        });
    }

    /**
     * Deletes the staging of every run that is no longer RUNNING (P3
     * assessments first, when installed); returns the number of rows removed.
     */
    public static function purgeFinishedRuns(Connection $db): int
    {
        $finished = "SELECT f.id FROM identity_ref.import_files f JOIN identity_ref.import_runs r ON r.id = f.run_id WHERE r.status <> 'RUNNING'";
        if ($db->selectOne("SELECT to_regclass('identity_ref_staging.assessments') IS NOT NULL AS p3")->p3) {
            $db->delete("DELETE FROM identity_ref_staging.assessments WHERE file_id IN ({$finished})");
        }
        $rows = $db->delete("DELETE FROM identity_ref_staging.rows WHERE file_id IN ({$finished})");
        $db->delete("DELETE FROM identity_ref_staging.file_chunks WHERE file_id IN ({$finished})");

        return $rows;
    }

    /**
     * @param  list<array{file: string, code: string, line: ?int}>  $problems
     * @return list<string>
     */
    private static function describe(array $problems): array
    {
        return array_map(fn ($p) => $p['code'].' '.$p['file'].($p['line'] !== null ? ' line '.$p['line'] : ''), $problems);
    }

    /** @param list<string> $problems */
    private function fail(string $reason, string $message, array $problems, ?string $runId): never
    {
        $e = new IdentityImportException($reason, $message, $problems);
        $e->runId = $runId;
        throw $e;
    }
}
