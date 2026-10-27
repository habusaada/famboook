<?php

namespace App\Support\IdentityRef;

use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Deletes the staging of finished import runs in short, independently
 * committed batches (FB-CIVIL-01A staging purge remediation).
 *
 * Why batches by primary-key range: every deleted staged row fires the
 * ON DELETE RESTRICT check of assessments(file_id, record_no), and every
 * deleted chunk the check of rows(file_id, chunk_no); neither has a matching
 * index, and one statement over millions of rows also spools every deleted
 * row into the delete guards' transition tables. One file at a time:
 *   1. batches of at most $batchSize records (record_no range of the primary
 *      key): the file's assessments (P3, when installed), then its rows —
 *      one transaction per batch, committed;
 *   2. once the file has no rows left: its chunk checkpoints, one transaction.
 *
 * Scope: only files of runs in FINISHED_RUN_STATUSES — never a RUNNING run
 * (the database delete guard refuses a STAGED file of a RUNNING run anyway).
 * Nothing outside identity_ref_staging is touched.
 *
 * Resumable and idempotent without any bookkeeping: progress is the data
 * itself (whatever was committed is gone; a re-run starts at the lowest
 * remaining record of each file and finds nothing once complete). The caller
 * holds the import advisory lock, so no staging, preparation or publication
 * runs meanwhile, and must not be inside a transaction — otherwise no batch
 * would be committed on its own.
 *
 * Never throws: an interruption is logged (redacted) and reported as
 * complete = false with the counts committed so far.
 */
final class IdentityStagingPurger
{
    public const DEFAULT_BATCH_SIZE = 1000;

    /** Run statuses whose staging is no longer needed — every status except RUNNING. */
    public const FINISHED_RUN_STATUSES = ['SUCCEEDED', 'FAILED', 'REJECTED', 'NOTHING_TO_DO'];

    private readonly int $batchSize;

    /** @var Closure(string, array<string, mixed>): void */
    private readonly Closure $hook;

    /**
     * @param  ?Closure(string, array<string, mixed>): void  $hook  'purge.batch_committed' after every committed batch (heartbeat, test seam)
     */
    public function __construct(
        private readonly Connection $db,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly LoggerInterface $logger = new NullLogger,
        ?Closure $hook = null,
    ) {
        $this->batchSize = max(1, $batchSize);
        $this->hook = $hook ?? static function (): void {};
    }

    /**
     * @return array{complete: bool, files_purged: int, rows_purged: int, assessments_purged: int, chunks_purged: int, batches: int}
     */
    public function purge(): array
    {
        if ($this->db->transactionLevel() > 0) {
            throw new LogicException('The staging purge commits every batch; it must not run inside a transaction.');
        }
        $report = ['complete' => false, 'files_purged' => 0, 'rows_purged' => 0, 'assessments_purged' => 0, 'chunks_purged' => 0, 'batches' => 0];
        try {
            $assessments = $this->assessmentsInstalled();
            foreach ($this->finishedFiles() as $file) {
                $this->purgeFile((int) $file->id, $file->release_id === null ? null : (int) $file->release_id, $assessments, $report);
                $report['files_purged']++;
            }
            $report['complete'] = true;
        } catch (Throwable $e) {
            $this->logger->warning('identity_ref.staging.purge_interrupted', array_diff_key($report, ['complete' => 0])
                + ['error' => IdentityRefErrorRedactor::redact($e)]);
        }

        return $report;
    }

    /**
     * Read-only: what a purge would delete, and the staging it never touches.
     *
     * @return array{files: int, rows: int, assessments: int, chunks: int, protected_rows: int}
     */
    public function remaining(): array
    {
        $finished = $this->finishedRunsPredicate();
        $files = "SELECT f.id FROM identity_ref.import_files f JOIN identity_ref.import_runs r ON r.id = f.run_id WHERE {$finished}";
        $count = fn (string $sql) => (int) $this->db->selectOne($sql, self::FINISHED_RUN_STATUSES)->n;

        return [
            'files' => $count("SELECT count(DISTINCT c.file_id) AS n FROM identity_ref_staging.file_chunks c WHERE c.file_id IN ({$files})"),
            'rows' => $count("SELECT count(*) AS n FROM identity_ref_staging.rows s WHERE s.file_id IN ({$files})"),
            'assessments' => $this->assessmentsInstalled()
                ? $count("SELECT count(*) AS n FROM identity_ref_staging.assessments a WHERE a.file_id IN ({$files})") : 0,
            'chunks' => $count("SELECT count(*) AS n FROM identity_ref_staging.file_chunks c WHERE c.file_id IN ({$files})"),
            'protected_rows' => $count("SELECT count(*) AS n FROM identity_ref_staging.rows s WHERE s.file_id NOT IN ({$files})"),
        ];
    }

    /**
     * Read-only: the finished runs that still hold staging, oldest first — ids, statuses and counts only.
     *
     * @return list<array{run_id: string, status: string, error_code: ?string, files: int, chunks: int, rows: int}>
     */
    public function eligibleRuns(): array
    {
        return array_map(fn ($r) => ['run_id' => $r->run_id, 'status' => $r->status, 'error_code' => $r->error_code,
            'files' => (int) $r->files, 'chunks' => (int) $r->chunks, 'rows' => (int) $r->rows],
            $this->db->select("SELECT r.id AS run_id, r.status, r.error_code, count(DISTINCT c.file_id) AS files, count(*) AS chunks,
                    (SELECT count(*) FROM identity_ref_staging.rows s JOIN identity_ref.import_files f2 ON f2.id = s.file_id WHERE f2.run_id = r.id) AS rows
                FROM identity_ref_staging.file_chunks c
                JOIN identity_ref.import_files f ON f.id = c.file_id
                JOIN identity_ref.import_runs r ON r.id = f.run_id
                WHERE {$this->finishedRunsPredicate()}
                GROUP BY r.id, r.status, r.error_code, r.started_at
                ORDER BY r.started_at, r.id", self::FINISHED_RUN_STATUSES));
    }

    /**
     * @param  array<string, int|bool>  $report
     */
    private function purgeFile(int $fileId, ?int $releaseId, bool $assessments, array &$report): void
    {
        $next = $this->db->selectOne('SELECT min(record_no) AS n FROM identity_ref_staging.rows WHERE file_id = ?', [$fileId])->n;
        while ($next !== null) {
            $from = (int) $next - 1;
            $to = $from + $this->batchSize;
            [$a, $r] = $this->db->transaction(function () use ($fileId, $releaseId, $assessments, $from, $to) {
                // The assessments of a run all belong to its single release (dataset_releases.run_id is UNIQUE);
                // any other would make the rows delete fail on its RESTRICT foreign key, never orphan a row.
                $a = $assessments && $releaseId !== null
                    ? $this->db->delete('DELETE FROM identity_ref_staging.assessments WHERE release_id = ? AND file_id = ? AND record_no > ? AND record_no <= ?',
                        [$releaseId, $fileId, $from, $to])
                    : 0;
                $r = $this->db->delete('DELETE FROM identity_ref_staging.rows WHERE file_id = ? AND record_no > ? AND record_no <= ?', [$fileId, $from, $to]);

                return [$a, $r];
            });
            $report['assessments_purged'] += $a;
            $report['rows_purged'] += $r;
            $report['batches']++;
            ($this->hook)('purge.batch_committed', ['file_id' => $fileId, 'rows' => $r, 'assessments' => $a]);
            $next = $this->db->selectOne('SELECT min(record_no) AS n FROM identity_ref_staging.rows WHERE file_id = ? AND record_no > ?', [$fileId, $to])->n;
        }
        // All rows are gone (committed): the chunk RESTRICT checks now only pass over dead index entries.
        $report['chunks_purged'] += $this->db->transaction(fn () => $this->db->delete('DELETE FROM identity_ref_staging.file_chunks WHERE file_id = ?', [$fileId]));
        ($this->hook)('purge.batch_committed', ['file_id' => $fileId, 'rows' => 0, 'assessments' => 0]);
    }

    /** @return list<object{id: int, release_id: ?int}> files of finished runs that still hold staging (every staged row has a chunk) */
    private function finishedFiles(): array
    {
        return $this->db->select("SELECT f.id, d.id AS release_id FROM identity_ref.import_files f
                JOIN identity_ref.import_runs r ON r.id = f.run_id
                LEFT JOIN identity_ref.dataset_releases d ON d.run_id = f.run_id
            WHERE {$this->finishedRunsPredicate()}
              AND EXISTS (SELECT 1 FROM identity_ref_staging.file_chunks c WHERE c.file_id = f.id)
            ORDER BY f.id", self::FINISHED_RUN_STATUSES);
    }

    private function finishedRunsPredicate(): string
    {
        return 'r.status IN ('.implode(', ', array_fill(0, count(self::FINISHED_RUN_STATUSES), '?')).')';
    }

    private function assessmentsInstalled(): bool
    {
        return (bool) $this->db->selectOne("SELECT to_regclass('identity_ref_staging.assessments') IS NOT NULL AS p3")->p3;
    }
}
