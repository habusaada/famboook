<?php

namespace App\Actions;

use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportPolicy;
use App\Support\IdentityRef\IdentityImportSession;
use App\Support\IdentityRef\IdentityRefErrorRedactor;
use App\Support\IdentityRef\IdentityStagingPurger;
use Closure;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Turns a fully staged import run into an immutable identity reference
 * release (FB-CIVIL-01A-P3), on the P1 release model and the P2 staging.
 *
 *   assess   read-only: release-level checks, NEW/CHANGED/UNCHANGED/CONFLICT
 *            counts, warnings and the policy verdict (validate + preview)
 *   prepare  begin_preparation() records policy, assessments and counts and
 *            creates the BUILDING release (fails closed on an unresolved
 *            policy); then versions and presence are materialized in short
 *            chunked transactions, missing identities carried forward and
 *            the preparation completed. Nothing is visible to readers: only
 *            the active-release pointer is read. Re-running resumes.
 *   verify   verify_release(): the database recomputes the classification
 *            and proves the release equals it exactly.
 *   publish  ONE short transaction: publish_release() (lock, stale-base,
 *            coverage checks, publication gate, pointer switch) + the run
 *            closed SUCCEEDED. Then the staging of finished runs is purged
 *            in committed batches; a purge failure is logged and never
 *            affects the publication.
 *   status   read-only inspection.
 *
 * Logged and returned: run and release ids, statuses, counts, codes and
 * timings — never a value from the source.
 */
final class PublishIdentityReleaseAction
{
    public const DEFAULT_CHUNK_SIZE = 5000;

    private const TEST_POLICY_ID = 'TEST-SYNTHETIC';

    /** Release-level codes raised by the database, mapped to operator reasons. */
    private const DB_CODES = [
        'IDENTITY_REF_RUN_NOT_RUNNING' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_SOURCE_TABLE_UNSUPPORTED' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_STAGING_INCOMPLETE' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_CHECKSUM_NOT_VERIFIED' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_ROW_COUNT_INCONSISTENT' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_STAGING_EMPTY' => IdentityImportException::VALIDATION_FAILED,
        'IDENTITY_REF_COMPARISON_POLICY_UNSUPPORTED' => IdentityImportException::POLICY_NOT_APPROVED,
        'IDENTITY_REF_POLICY_QUARANTINE_UNRESOLVED' => IdentityImportException::POLICY_BLOCKED,
        'IDENTITY_REF_QUARANTINE_THRESHOLD_EXCEEDED' => IdentityImportException::POLICY_BLOCKED,
        'IDENTITY_REF_QUARANTINE_NOT_ALLOWED' => IdentityImportException::POLICY_BLOCKED,
        'IDENTITY_REF_VERIFY_INCOMING_MISMATCH' => IdentityImportException::VERIFICATION_FAILED,
        'IDENTITY_REF_POLICY_BLANK_FIELD_UNRESOLVED' => IdentityImportException::POLICY_BLOCKED,
        'IDENTITY_REF_RELEASE_STATE_INVALID' => IdentityImportException::RELEASE_STATE_INVALID,
        'IDENTITY_REF_RELEASE_NOT_BUILDING' => IdentityImportException::RELEASE_STATE_INVALID,
        'IDENTITY_REF_RELEASE_NOT_PREPARED' => IdentityImportException::NOT_VERIFIED,
        'IDENTITY_REF_RELEASE_NOT_VERIFIED' => IdentityImportException::NOT_VERIFIED,
        'IDENTITY_REF_PREPARATION_INCOMPLETE' => IdentityImportException::PREPARATION_INTERRUPTED,
        'IDENTITY_REF_VERIFY_COUNTS_MISMATCH' => IdentityImportException::VERIFICATION_FAILED,
        'IDENTITY_REF_VERIFY_PRESENCE_MISMATCH' => IdentityImportException::VERIFICATION_FAILED,
        'IDENTITY_REF_VERIFY_CARRY_FORWARD_MISMATCH' => IdentityImportException::VERIFICATION_FAILED,
        'IDENTITY_REF_RELEASE_STALE_BASE' => IdentityImportException::RELEASE_STATE_INVALID,
        'IDENTITY_REF_PUBLICATION_IN_PROGRESS' => IdentityImportException::CONCURRENT_IMPORT,
        'IDENTITY_REF_RELEASE_DROPS_IDENTITIES' => IdentityImportException::VERIFICATION_FAILED,
        'IDENTITY_REF_RELEASE_EMPTY' => IdentityImportException::VALIDATION_FAILED,
    ];

    /**
     * Read-only validation and comparison preview.
     *
     * @return array<string, mixed>
     *
     * @throws IdentityImportException
     */
    public function assess(Connection $db, string $runId, IdentityImportPolicy $policy, string $target): array
    {
        return IdentityImportSession::readOnly($db, $target, fn () => $this->assessRun($db, $runId, $policy));
    }

    /**
     * @param  ?Closure(string, array<string, mixed>): void  $hook  test seam: 'prepare.chunk_committed', 'prepare.before_complete'
     * @return array<string, mixed>
     *
     * @throws IdentityImportException
     */
    public function prepare(Connection $db, string $runId, IdentityImportPolicy $policy, string $target, int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?LoggerInterface $logger = null, ?Closure $hook = null): array
    {
        $logger ??= new NullLogger;
        $hook ??= static function (): void {};

        return IdentityImportSession::run($db, $target, function () use ($db, $runId, $policy, $chunkSize, $logger, $hook) {
            $this->assertPolicyUsable($policy->id, $policy->comparison);
            $assessment = $this->assessRun($db, $runId, $policy);
            if ($assessment['fatal'] !== []) {
                $logger->warning('identity_ref.release.validation_failed', ['run_id' => $runId, 'fatal' => $assessment['fatal']]);
                $this->fail(IdentityImportException::VALIDATION_FAILED, 'Release-level validation failed; nothing was written.', $assessment['fatal'], $runId);
            }
            if ($assessment['blocked'] !== []) {
                $logger->warning('identity_ref.release.policy_blocked', ['run_id' => $runId, 'blocked' => $assessment['blocked']]);
                $this->fail(IdentityImportException::POLICY_BLOCKED, 'The approved policy does not cover this data; nothing was written.', $assessment['blocked'], $runId);
            }

            $started = microtime(true);
            $timings = [];
            $releaseId = null;
            try {
                // Fresh statistics for the set-based classification (staging was just bulk-loaded).
                $db->select('SELECT identity_ref.refresh_statistics()');
                $releaseId = (int) $db->selectOne('SELECT identity_ref.begin_preparation(?::uuid, ?, ?, ?, ?, ?, ?::numeric) AS id', [
                    $runId, $policy->id, $policy->fingerprint(), $policy->comparison, $policy->blankField,
                    $policy->quarantineMaxRows, $policy->quarantineMaxRatio,
                ])->id;
                $timings['begin'] = round(microtime(true) - $started, 3);
                $prep = $db->selectOne('SELECT status FROM identity_ref.release_preparations WHERE release_id = ?', [$releaseId]);
                $materialized = ['chunks' => 0];
                if ($prep->status === 'PREPARING') {
                    $t = microtime(true);
                    $materialized = $this->materialize($db, $runId, $releaseId, max(1, $chunkSize), $hook);
                    $timings['materialize'] = round(microtime(true) - $t, 3);
                    $t = microtime(true);
                    $db->select('SELECT identity_ref.refresh_statistics()');
                    $hook('prepare.before_complete', ['release_id' => $releaseId]);
                    $db->select('SELECT identity_ref.carry_forward_missing(?)', [$releaseId]);
                    $db->select('SELECT identity_ref.complete_preparation(?)', [$releaseId]);
                    $timings['finalize'] = round(microtime(true) - $t, 3);
                }
            } catch (IdentityImportException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->failFromDatabase($e, IdentityImportException::PREPARATION_INTERRUPTED,
                    'Preparation stopped; the active release is unchanged. Re-run prepare with the same policy to resume.', $runId, $releaseId, $logger);
            }

            $report = $this->releaseReport($db, $releaseId) + ['chunks_materialized' => $materialized['chunks'], 'seconds' => round(microtime(true) - $started, 3), 'timings' => $timings];
            $logger->info('identity_ref.release.prepared', $report);

            return $report;
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IdentityImportException
     */
    public function verify(Connection $db, int $releaseId, string $target, ?LoggerInterface $logger = null): array
    {
        $logger ??= new NullLogger;

        return IdentityImportSession::run($db, $target, function () use ($db, $releaseId, $logger) {
            $prep = $db->selectOne('SELECT run_id, policy_id, comparison_policy FROM identity_ref.release_preparations WHERE release_id = ?', [$releaseId]);
            if ($prep === null) {
                $this->fail(IdentityImportException::NOT_VERIFIED, 'The release has no preparation.', [], null, $releaseId);
            }
            $this->assertPolicyUsable($prep->policy_id, $prep->comparison_policy);
            $started = microtime(true);
            try {
                $db->select('SELECT identity_ref.verify_release(?)', [$releaseId]);
            } catch (Throwable $e) {
                $this->failFromDatabase($e, IdentityImportException::VERIFICATION_FAILED, 'Verification failed; the release cannot be published.',
                    $prep->run_id, $releaseId, $logger);
            }
            $report = $this->releaseReport($db, $releaseId) + ['seconds' => round(microtime(true) - $started, 3)];
            $logger->info('identity_ref.release.verified', $report);

            return $report;
        });
    }

    /**
     * Activates a VERIFIED release. Idempotent: an already published release is reported, not re-published.
     *
     * @param  ?Closure(string, array<string, mixed>): void  $hook  test seam: 'publish.before_commit'; 'purge.batch_committed' (heartbeat)
     * @return array<string, mixed>
     *
     * @throws IdentityImportException
     */
    public function publish(Connection $db, int $releaseId, string $target, ?LoggerInterface $logger = null, ?Closure $hook = null,
        int $purgeBatchSize = IdentityStagingPurger::DEFAULT_BATCH_SIZE): array
    {
        $logger ??= new NullLogger;
        $hook ??= static function (): void {};

        return IdentityImportSession::run($db, $target, function () use ($db, $releaseId, $logger, $hook, $purgeBatchSize) {
            $rel = $db->selectOne('SELECT d.id, d.run_id, d.status, p.status AS prep_status, p.policy_id, p.comparison_policy
                FROM identity_ref.dataset_releases d LEFT JOIN identity_ref.release_preparations p ON p.release_id = d.id WHERE d.id = ?', [$releaseId]);
            if ($rel === null) {
                $this->fail(IdentityImportException::RELEASE_STATE_INVALID, 'Unknown release.', [], null, $releaseId);
            }
            if ($rel->status === 'PUBLISHED') {
                return ['outcome' => 'ALREADY_PUBLISHED'] + $this->releaseReport($db, $releaseId);
            }
            if ($rel->status !== 'BUILDING') {
                $this->fail(IdentityImportException::RELEASE_STATE_INVALID, 'Only a BUILDING release can be published.', [$rel->status], $rel->run_id, $releaseId);
            }
            if ($rel->prep_status !== 'VERIFIED') {
                $this->fail(IdentityImportException::NOT_VERIFIED, 'The release must be prepared and verified first.', [$rel->prep_status ?? 'NO_PREPARATION'], $rel->run_id, $releaseId);
            }
            $this->assertPolicyUsable($rel->policy_id, $rel->comparison_policy);

            $started = microtime(true);
            try {
                $db->transaction(function () use ($db, $rel, $releaseId, $hook) {
                    $db->select('SELECT identity_ref.publish_release(?)', [$releaseId]);
                    $db->update("UPDATE identity_ref.import_runs SET status = 'SUCCEEDED', finished_at = now(),
                            new_records = p.new_count, unchanged = p.unchanged_count, carried_forward = p.carried_forward_count,
                            new_versions = (SELECT count(*) FROM identity_ref.record_versions v WHERE v.created_run_id = ?::uuid),
                            issues = (SELECT count(*) FROM identity_ref.import_issues i WHERE i.run_id = ?::uuid)
                        FROM identity_ref.release_preparations p WHERE p.release_id = ? AND identity_ref.import_runs.id = ?::uuid",
                        [$rel->run_id, $rel->run_id, $releaseId, $rel->run_id]);
                    $hook('publish.before_commit', ['release_id' => $releaseId]);
                });
            } catch (Throwable $e) {
                $this->failFromDatabase($e, IdentityImportException::PUBLICATION_FAILED,
                    'Publication was rolled back; the previously active release is unchanged.', $rel->run_id, $releaseId, $logger);
            }
            $activation = round(microtime(true) - $started, 3);

            // After the commit, outside any transaction: batches commit on their own and never touch the publication.
            $purge = (new IdentityStagingPurger($db, $purgeBatchSize, $logger, $hook))->purge();
            $purged = $purge['complete'] ? $purge['rows_purged'] : null;
            if (! $purge['complete']) {
                // The release is active; leftover staging is purged by the next abort, purge or publication.
                $logger->warning('identity_ref.release.staging_purge_failed', ['release_id' => $releaseId, 'rows_purged_before_interruption' => $purge['rows_purged']]);
            }
            $report = ['outcome' => 'PUBLISHED', 'activation_seconds' => $activation, 'staging_rows_purged' => $purged] + $this->releaseReport($db, $releaseId);
            $logger->info('identity_ref.release.published', $report);

            return $report;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Connection $db, string $target): array
    {
        return IdentityImportSession::readOnly($db, $target, function () use ($db) {
            $active = $db->selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1')?->release_id;
            $runs = array_map(fn ($r) => (array) $r, $db->select('SELECT id, status, error_code, expected_rows, rows_loaded, issues, started_at, finished_at
                FROM identity_ref.import_runs ORDER BY started_at DESC LIMIT 10'));
            $releases = array_map(fn ($r) => (array) $r, $db->select('SELECT d.id, d.run_id, d.status, d.base_release_id, d.record_count, p.status AS preparation,
                    p.policy_id, p.new_count, p.changed_count, p.unchanged_count, p.conflict_count, p.carried_forward_count
                FROM identity_ref.dataset_releases d LEFT JOIN identity_ref.release_preparations p ON p.release_id = d.id ORDER BY d.id DESC LIMIT 10'));

            return ['active_release' => $active === null ? null : (int) $active, 'runs' => $runs, 'releases' => $releases];
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array<string, mixed>
     */
    private function assessRun(Connection $db, string $runId, IdentityImportPolicy $policy): array
    {
        $fatal = [];
        if (! $policy->comparisonSupported()) {
            $fatal[] = 'COMPARISON_POLICY_UNSUPPORTED';
        }
        $run = preg_match('/^[0-9a-f-]{36}$/', $runId) === 1
            ? $db->selectOne('SELECT id, status, source_table, expected_rows, rows_loaded FROM identity_ref.import_runs WHERE id = ?::uuid', [$runId])
            : null;
        if ($run === null) {
            return $this->assessment($runId, null, ['RUN_NOT_FOUND'], [], null, [], []);
        }
        if ($run->status !== 'RUNNING') {
            $fatal[] = 'RUN_NOT_RUNNING';
        }
        if ($run->source_table !== 't03maindata') {
            $fatal[] = 'SOURCE_TABLE_UNSUPPORTED';
        }
        $files = $db->selectOne("SELECT count(*) AS n, count(*) FILTER (WHERE status <> 'STAGED') AS unstaged,
                count(*) FILTER (WHERE actual_sha256 IS NULL OR actual_sha256 <> expected_sha256) AS unverified,
                coalesce(sum(loaded_rows), 0) AS loaded
            FROM identity_ref.import_files WHERE run_id = ?::uuid", [$runId]);
        if ((int) $files->n === 0 || (int) $files->unstaged > 0) {
            $fatal[] = 'STAGING_INCOMPLETE';
        }
        if ((int) $files->unverified > 0) {
            $fatal[] = 'CHECKSUM_NOT_VERIFIED';
        }
        $staged = (int) $db->selectOne('SELECT count(*) AS n FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id
            WHERE f.run_id = ?::uuid', [$runId])->n;
        if ($staged !== (int) $run->expected_rows || (int) $files->loaded !== (int) $run->expected_rows || (int) $run->rows_loaded !== (int) $run->expected_rows) {
            $fatal[] = 'ROW_COUNT_INCONSISTENT';
        }
        $existing = $db->selectOne('SELECT d.id, d.status, d.base_release_id, p.policy_fingerprint FROM identity_ref.dataset_releases d
            LEFT JOIN identity_ref.release_preparations p ON p.release_id = d.id WHERE d.run_id = ?::uuid', [$runId]);
        if ($existing !== null && ($existing->status !== 'BUILDING' || $existing->policy_fingerprint !== $policy->fingerprint())) {
            $fatal[] = 'RELEASE_STATE_INVALID';
        }
        if ($fatal !== []) {
            return $this->assessment($runId, null, $fatal, [], null, [], []);
        }

        // Planner statistics only (no data is written): freshly staged data must not be planned as empty.
        $db->select('SELECT identity_ref.refresh_statistics()');
        $base = $existing?->base_release_id ?? $db->selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1')?->release_id;
        $base = $base === null ? null : (int) $base;
        $rows = $db->select('SELECT classification, conflict_code, count(*) AS n, count(*) FILTER (WHERE blank_over_value) AS bov,
                count(*) FILTER (WHERE base_record_id IS NOT NULL AND classification <> \'CONFLICT\') AS matched
            FROM identity_ref_staging.assess_run(?::uuid, ?::bigint, ?) GROUP BY classification, conflict_code', [$runId, $base, $policy->blankField]);
        $counts = ['staged' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'conflict' => 0, 'blank_over_value' => 0];
        $conflicts = [];
        $matched = 0;
        foreach ($rows as $r) {
            $counts['staged'] += (int) $r->n;
            $counts[strtolower($r->classification)] += (int) $r->n;
            $counts['blank_over_value'] += (int) $r->bov;
            $matched += (int) $r->matched;
            if ($r->conflict_code !== null) {
                $conflicts[$r->conflict_code] = (int) $r->n;
            }
        }
        ksort($conflicts);
        $baseTotal = $base === null ? 0 : (int) $db->selectOne('SELECT count(*) AS n FROM identity_ref.release_presence WHERE release_id = ?', [$base])->n;
        $carried = $baseTotal - $matched;
        // Same keys, same order as the recorded preparation (releaseReport).
        $counts = ['staged' => $counts['staged'], 'new' => $counts['new'], 'changed' => $counts['changed'], 'unchanged' => $counts['unchanged'],
            'conflict' => $counts['conflict'], 'carried_forward' => $carried, 'blank_over_value' => $counts['blank_over_value'],
            'expected_presence' => $counts['new'] + $counts['changed'] + $counts['unchanged'] + $carried];
        $warnings = json_decode($db->selectOne('SELECT identity_ref_staging.assess_warnings(?::uuid, ?::bigint) AS w', [$runId, $base])->w, true);
        ksort($warnings);

        $blocked = [];
        if ($counts['conflict'] > 0 && ! $policy->quarantineResolved()) {
            $blocked[] = 'POLICY_QUARANTINE_UNRESOLVED';
        } elseif ($counts['conflict'] > 0 && $policy->quarantineMaxRows === 0) {
            $blocked[] = 'QUARANTINE_NOT_ALLOWED';                 // ZERO_UNRESOLVED_QUARANTINE
        } elseif ($counts['conflict'] > 0 && ($counts['conflict'] > $policy->quarantineMaxRows
            || $counts['conflict'] > (float) $policy->quarantineMaxRatio * $counts['staged'])) {
            $blocked[] = 'QUARANTINE_THRESHOLD_EXCEEDED';
        }
        if ($counts['blank_over_value'] > 0 && $policy->blankField === IdentityImportPolicy::BLANK_UNRESOLVED) {
            $blocked[] = 'POLICY_BLANK_FIELD_UNRESOLVED';
        }
        if ($counts['staged'] === 0) {
            $fatal[] = 'STAGING_EMPTY';
        }

        return $this->assessment($runId, $base, $fatal, $blocked, $counts, $conflicts, $warnings);
    }

    /**
     * @param  list<string>  $fatal
     * @param  list<string>  $blocked
     * @param  array<string, int>|null  $counts
     * @param  array<string, int>  $conflicts
     * @param  array<string, int>  $warnings
     * @return array<string, mixed>
     */
    private function assessment(string $runId, ?int $base, array $fatal, array $blocked, ?array $counts, array $conflicts, array $warnings): array
    {
        return [
            'run_id' => $runId, 'base_release' => $base, 'fatal' => $fatal, 'blocked' => $blocked,
            'counts' => $counts, 'conflicts' => $conflicts, 'warnings' => $warnings,
            'publishable' => $fatal === [] && $blocked === [] && $counts !== null,
        ];
    }

    /**
     * Versions and presence for every accepted staged row, one short
     * transaction per window of records. Idempotent (re-running a window
     * changes nothing), so an interrupted preparation simply runs again.
     *
     * @param  Closure(string, array<string, mixed>): void  $hook
     * @return array{chunks: int}
     */
    private function materialize(Connection $db, string $runId, int $releaseId, int $chunkSize, Closure $hook): array
    {
        $chunks = 0;
        $files = $db->select('SELECT id, loaded_rows FROM identity_ref.import_files WHERE run_id = ?::uuid ORDER BY file_name', [$runId]);
        $from = 'FROM identity_ref_staging.assessments a JOIN identity_ref_staging.rows r ON r.file_id = a.file_id AND r.record_no = a.record_no';
        $where = "WHERE a.release_id = ? AND a.file_id = ? AND a.record_no BETWEEN ? AND ? AND a.classification <> 'CONFLICT'";
        foreach ($files as $file) {
            for ($lo = 1; $lo <= (int) $file->loaded_rows; $lo += $chunkSize) {
                $bind = [$releaseId, (int) $file->id, $lo, $lo + $chunkSize - 1];
                $db->transaction(function () use ($db, $from, $where, $bind, $runId, $releaseId) {
                    // A window touches a few thousand rows of tables holding millions: index lookups, never
                    // a full scan per window — also inside the P1 version trigger, whose plan would otherwise
                    // depend on statistics taken while record_versions was still empty (transaction-local).
                    $db->unprepared('SET LOCAL enable_seqscan = off; SET LOCAL enable_hashjoin = off; SET LOCAL enable_mergejoin = off');
                    $db->insert("INSERT INTO identity_ref.records (mainid, first_seen_run_id)
                        SELECT a.mainid, ?::uuid {$from} {$where} AND a.classification = 'NEW'
                        ON CONFLICT (mainid) DO NOTHING", [$runId, ...$bind]);
                    // version_no and content_sha256 are computed by the P1 trigger; an identical payload reuses its version.
                    $db->insert("INSERT INTO identity_ref.record_versions (record_id, version_no, content_sha256, source_payload, created_run_id)
                        SELECT rec.id, 1, decode(repeat('00', 32), 'hex'), coalesce(a.effective_payload, r.source_payload), ?::uuid
                          {$from} JOIN identity_ref.records rec ON rec.mainid = a.mainid
                          {$where} AND a.classification IN ('NEW', 'CHANGED')
                        ON CONFLICT (record_id, content_sha256) DO NOTHING", [$runId, ...$bind]);
                    $db->insert("INSERT INTO identity_ref.release_presence (release_id, record_id, record_version_id, source_state)
                        SELECT ?, rec.id, v.id, 'PRESENT'
                          {$from} JOIN identity_ref.records rec ON rec.mainid = a.mainid
                          JOIN identity_ref.record_versions v ON v.record_id = rec.id AND v.content_sha256 = a.content_sha256
                          {$where}
                        ON CONFLICT (release_id, record_id) DO NOTHING", [$releaseId, ...$bind]);
                    // PRESERVE_EXISTING_ON_BLANK: the source row exactly as received, where the published version differs from it.
                    $db->insert("INSERT INTO identity_ref.release_incoming (release_id, record_id, source_payload)
                        SELECT ?, rec.id, r.source_payload
                          {$from} JOIN identity_ref.records rec ON rec.mainid = a.mainid
                          {$where} AND a.effective_payload IS NOT NULL
                        ON CONFLICT (release_id, record_id) DO NOTHING", [$releaseId, ...$bind]);
                });
                $chunks++;
                $hook('prepare.chunk_committed', ['release_id' => $releaseId, 'chunk' => $chunks]);
            }
        }

        return ['chunks' => $chunks];
    }

    /** @return array<string, mixed> */
    private function releaseReport(Connection $db, int $releaseId): array
    {
        $r = $db->selectOne('SELECT d.id, d.run_id, d.status, d.base_release_id, d.record_count, p.status AS preparation, p.policy_id,
                p.comparison_policy, p.blank_field_policy, p.staged_rows, p.new_count, p.changed_count, p.unchanged_count, p.conflict_count,
                p.carried_forward_count, p.blank_over_value_count, p.expected_presence, p.warnings,
                (SELECT release_id FROM identity_ref.active_release WHERE id = 1) AS active_release
            FROM identity_ref.dataset_releases d LEFT JOIN identity_ref.release_preparations p ON p.release_id = d.id WHERE d.id = ?', [$releaseId]);

        return [
            'release_id' => (int) $r->id, 'run_id' => $r->run_id, 'release_status' => $r->status, 'preparation' => $r->preparation,
            'base_release' => $r->base_release_id === null ? null : (int) $r->base_release_id,
            'active_release' => $r->active_release === null ? null : (int) $r->active_release,
            'policy_id' => $r->policy_id, 'comparison_policy' => $r->comparison_policy, 'blank_field_policy' => $r->blank_field_policy,
            'counts' => $r->preparation === null ? null : [
                'staged' => (int) $r->staged_rows, 'new' => (int) $r->new_count, 'changed' => (int) $r->changed_count,
                'unchanged' => (int) $r->unchanged_count, 'conflict' => (int) $r->conflict_count,
                'carried_forward' => (int) $r->carried_forward_count, 'blank_over_value' => (int) $r->blank_over_value_count,
                'expected_presence' => (int) $r->expected_presence,
            ],
            'record_count' => $r->record_count === null ? null : (int) $r->record_count,
            'warnings' => $r->warnings === null ? [] : self::sorted(json_decode($r->warnings, true)),
        ];
    }

    /** @param array<string, int> $codes */
    private static function sorted(array $codes): array
    {
        ksort($codes);

        return $codes;
    }

    /** A policy may be used only if approved here (synthetic ones only under tests) and its comparison version is supported. */
    private function assertPolicyUsable(string $policyId, string $comparison): void
    {
        $approved = in_array($policyId, IdentityImportPolicy::approvedIds(), true)
            || ($policyId === self::TEST_POLICY_ID && app()->environment('testing'));
        if (! $approved || ! in_array($comparison, IdentityImportPolicy::SUPPORTED_COMPARISON, true)) {
            $this->fail(IdentityImportException::POLICY_NOT_APPROVED, 'The import policy is not approved here or its comparison version is unsupported.',
                [$approved ? 'COMPARISON_POLICY_UNSUPPORTED' : 'POLICY_NOT_APPROVED'], null);
        }
    }

    private function failFromDatabase(Throwable $e, string $defaultReason, string $message, ?string $runId, ?int $releaseId, LoggerInterface $logger): never
    {
        $redacted = IdentityRefErrorRedactor::redact($e);
        $code = preg_match('/code=(IDENTITY_REF_[A-Z0-9_]+)/', $redacted, $m) ? $m[1] : null;
        $reason = $code !== null && isset(self::DB_CODES[$code]) ? self::DB_CODES[$code] : $defaultReason;
        $logger->error('identity_ref.release.failed', ['run_id' => $runId, 'release_id' => $releaseId, 'reason' => $reason, 'error' => $redacted]);
        $this->fail($reason, $message.' '.$redacted, $code !== null ? [substr($code, strlen('IDENTITY_REF_'))] : [], $runId, $releaseId);
    }

    /** @param list<string> $problems */
    private function fail(string $reason, string $message, array $problems, ?string $runId, ?int $releaseId = null): never
    {
        $e = new IdentityImportException($reason, $message, $problems);
        $e->runId = $runId;
        $e->releaseId = $releaseId;
        throw $e;
    }
}
