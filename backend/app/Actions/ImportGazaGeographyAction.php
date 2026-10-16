<?php

namespace App\Actions;

use App\Enums\GazaGeographyImportStatus;
use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Enums\GazaLocationReviewStatus;
use App\Exceptions\GazaGeographyImportException;
use App\Models\GazaGeographyImportRun;
use App\Models\GazaLocation;
use App\Support\Geography\GazaGeographySource;
use App\Support\Geography\GazaGeographyWorkbook;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports one verified version of the Gaza geographic workbook into
 * gaza_locations and records its review findings in
 * gaza_location_review_flags (FB-GEO-01 Stage 1C; ADR-FB-GEO-01 §3.3
 * R1–R9).
 *
 * - Idempotent: a record already present with the same name, parent and
 *   type is left untouched (never reactivated, never re-stamped); a flag
 *   already present for (location, issue) is left untouched (its review
 *   status is never reset).
 * - Original values only: codes, names, parents and landmark types exactly
 *   as supplied; nothing is merged, renamed, re-parented or deactivated.
 *   Flagged records are imported active and selectable (Stage 1B Q1).
 * - A source that would CHANGE an imported record (name, parent or type) is
 *   refused with every difference listed; applying a revision needs explicit
 *   authorization (Stage 1B G10) and is not done here.
 * - A record imported earlier but absent from the source is reported, never
 *   deleted.
 * - Never reads or writes families, residences or any table other than the
 *   three geography tables.
 *
 * Lifecycle and audit (Stage 1C.1, ADR-FB-GEO-01 §4.4):
 * 1–2. The file, checksum, structure and records are validated (read-only).
 *      A failure is recorded as a REJECTED run — except a missing file,
 *      which is only reported (nothing identifies it).
 * 3.   One import at a time (PostgreSQL advisory lock + single-RUNNING
 *      unique index); a RUNNING run is committed on its own.
 * 4.   The geographic records, the flags and the run's SUCCEEDED outcome
 *      are written in ONE transaction.
 * 5–6. On any failure that transaction is rolled back (no partial records)
 *      and the run is closed as REJECTED (a refusal) or FAILED (unexpected),
 *      with a sanitized summary; the original exception is rethrown.
 * A dry run computes the same report, rolls back and records no run.
 */
final class ImportGazaGeographyAction
{
    /** Rows per INSERT (SQLite bound-parameter limit). */
    private const CHUNK = 50;

    /** PostgreSQL advisory-lock key ("FBGEO01" in ASCII). */
    public const LOCK_KEY = 0x464247454F3031;

    private const SUMMARY_LIMIT = 4000;

    /**
     * @return array{
     *   run_id: ?string,
     *   checksum: string,
     *   dry_run: bool,
     *   source: array<string, int>,
     *   created: array<string, int>,
     *   unchanged: array<string, int>,
     *   missing_from_source: list<string>,
     *   flags_created: array<string, int>,
     *   flags_existing: array<string, int>
     * }
     *
     * @throws GazaGeographyImportException
     */
    public function handle(GazaGeographySource $source, bool $dryRun = false): array
    {
        $startedAt = Carbon::now();

        // 1–2. Source file, checksum, structure and records: read-only.
        try {
            $nodes = GazaGeographyWorkbook::read($source);
            $findings = $this->findings($nodes, $source);
        } catch (GazaGeographyImportException $e) {
            // A missing file identifies nothing to record; a dry run records nothing.
            if ($e->reason !== GazaGeographyImportException::MISSING_FILE && ! $dryRun) {
                $this->recordRejection($source, $startedAt, $e);
            }
            throw $e;
        }

        if ($dryRun) {
            return $this->import($nodes, $findings, $source, null, dryRun: true);
        }

        // 3–6. One import at a time; the run row is committed OUTSIDE the
        // import transaction so it survives that transaction's rollback.
        $this->acquireLock($source, $startedAt);
        try {
            $run = $this->startRun($source, $startedAt, $nodes);
            try {
                return $this->import($nodes, $findings, $source, $run, dryRun: false);
            } catch (Throwable $e) {
                $this->finishUnsuccessful($run, $e);
                throw $e;
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * The import transaction: geographic records and flags, plus the run's
     * SUCCEEDED outcome, commit together or not at all.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array{issue: GazaLocationReviewIssue, key: string, notes: string}>  $findings
     */
    private function import(array $nodes, array $findings, GazaGeographySource $source, ?GazaGeographyImportRun $run, bool $dryRun): array
    {
        DB::beginTransaction();
        try {
            if (DB::getDriverName() === 'pgsql') {
                // Also blocks any other writer of the reference; readers are not blocked.
                DB::statement('LOCK TABLE gaza_locations IN SHARE ROW EXCLUSIVE MODE');
            }

            $existing = $this->existing();
            $this->refuseRevisions($nodes, $existing);

            $report = [
                'run_id' => $run?->id,
                'checksum' => $source->expectedSha256,
                'dry_run' => $dryRun,
                'source' => $this->countByLevel($nodes),
                'created' => $this->insertNew($nodes, $existing),
                'unchanged' => $this->countByLevel(array_intersect_key($nodes, $existing)),
                'missing_from_source' => array_values(array_keys(array_diff_key($existing, $nodes))),
                ...$this->recordFlags($findings),
            ];

            $run?->update([
                'status' => GazaGeographyImportStatus::SUCCEEDED,
                'finished_at' => Carbon::now(),
                'locations_created' => array_sum($report['created']),
                'locations_unchanged' => array_sum($report['unchanged']),
                'locations_missing_from_source' => count($report['missing_from_source']),
                'flags_created' => array_sum($report['flags_created']),
                'flags_unchanged' => array_sum($report['flags_existing']),
            ]);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }

    /**
     * PostgreSQL: a session-level advisory lock held for the whole run. It is
     * database-backed (shared by every process on the database) and released
     * automatically if the process dies. Other drivers rely on the
     * single-RUNNING unique index (startRun).
     */
    private function acquireLock(GazaGeographySource $source, Carbon $startedAt): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (! DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [self::LOCK_KEY])->locked) {
            $e = new GazaGeographyImportException(
                GazaGeographyImportException::CONCURRENT_IMPORT,
                'Another Gaza geographic import is running on this database; nothing was imported.',
            );
            $this->recordRejection($source, $startedAt, $e);
            throw $e;
        }
    }

    private function releaseLock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_unlock(?) AS released', [self::LOCK_KEY]);
        }
    }

    /**
     * Records the RUNNING run (committed on its own). On PostgreSQL the lock
     * proves no other import is alive, so a leftover RUNNING row is a run
     * whose process ended without finishing: it is closed as FAILED. Without
     * that proof (other drivers) a RUNNING row means a concurrent import.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function startRun(GazaGeographySource $source, Carbon $startedAt, array $nodes): GazaGeographyImportRun
    {
        $running = GazaGeographyImportRun::where('status', GazaGeographyImportStatus::RUNNING)->get();
        if ($running->isNotEmpty() && DB::getDriverName() !== 'pgsql') {
            $this->refuseConcurrent($source, $startedAt);
        }
        foreach ($running as $interrupted) {
            $interrupted->update([
                'status' => GazaGeographyImportStatus::FAILED,
                'finished_at' => Carbon::now(),
                'error_summary' => 'Interrupted: the run ended without finishing; closed by a later import. Its import transaction was never committed.',
            ]);
        }

        $counts = $this->countByLevel($nodes);
        try {
            return GazaGeographyImportRun::create([
                ...$this->provenance($source),
                'status' => GazaGeographyImportStatus::RUNNING,
                'started_at' => $startedAt,
                'governorates_count' => $counts['GOVERNORATE'],
                'cities_count' => $counts['CITY'],
                'areas_count' => $counts['AREA'],
                'neighborhoods_count' => $counts['NEIGHBORHOOD'],
                'landmarks_count' => $counts['LANDMARK'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // uq_gaza_import_single_running: another run started meanwhile.
            $this->refuseConcurrent($source, $startedAt);
        }
    }

    private function refuseConcurrent(GazaGeographySource $source, Carbon $startedAt): never
    {
        $e = new GazaGeographyImportException(
            GazaGeographyImportException::CONCURRENT_IMPORT,
            'Another Gaza geographic import run is RUNNING; nothing was imported.',
        );
        $this->recordRejection($source, $startedAt, $e);
        throw $e;
    }

    /** A refused attempt, recorded as REJECTED. Never hides the original refusal. */
    private function recordRejection(GazaGeographySource $source, Carbon $startedAt, GazaGeographyImportException $e): void
    {
        try {
            $run = GazaGeographyImportRun::create([
                ...$this->provenance($source),
                'status' => GazaGeographyImportStatus::REJECTED,
                'started_at' => $startedAt,
                'finished_at' => Carbon::now(),
                'error_summary' => $this->summary($e),
            ]);
            $e->runId = $run->id;
        } catch (Throwable $recording) {
            report($recording);
        }
    }

    /** After the import transaction rolled back: REJECTED for a refusal, FAILED otherwise. */
    private function finishUnsuccessful(GazaGeographyImportRun $run, Throwable $e): void
    {
        try {
            $run->update([
                'status' => $e instanceof GazaGeographyImportException
                    ? GazaGeographyImportStatus::REJECTED
                    : GazaGeographyImportStatus::FAILED,
                'finished_at' => Carbon::now(),
                'error_summary' => $this->summary($e),
            ]);
            if ($e instanceof GazaGeographyImportException) {
                $e->runId = $run->id;
            }
        } catch (Throwable $recording) {
            report($recording);
        }
    }

    /** @return array{source_authority: string, source_filename: string, source_sha256: string, source_version: ?string} */
    private function provenance(GazaGeographySource $source): array
    {
        return [
            'source_authority' => $source->authority,
            // File name only: no local path is stored.
            'source_filename' => basename($source->path),
            // The file actually read (differs from the expected value on a checksum mismatch).
            'source_sha256' => (string) hash_file('sha256', $source->path),
            'source_version' => $source->version,
        ];
    }

    /**
     * Sanitized: a refusal keeps its reason and problems (source rows and
     * values only); an unexpected error keeps only its class and SQLSTATE —
     * never the message, which may carry SQL, host, database or credentials.
     */
    private function summary(Throwable $e): string
    {
        if ($e instanceof GazaGeographyImportException) {
            $text = "[{$e->reason}] {$e->getMessage()}";
            if ($e->problems !== []) {
                $text .= "\n- ".implode("\n- ", $e->problems);
            }

            return Str::limit($text, self::SUMMARY_LIMIT);
        }
        $state = $e instanceof QueryException ? ' (SQLSTATE '.($e->errorInfo[0] ?? $e->getCode()).')' : '';

        return 'Unexpected '.class_basename($e).$state.'; the import transaction was rolled back. Details are not stored here.';
    }

    /**
     * Imported records keyed "LEVEL:code", with the parent as a key too.
     *
     * @return array<string, array{name: string, parent_key: ?string, landmark_type_code: ?string, landmark_type_name: ?string}>
     */
    private function existing(): array
    {
        $rows = DB::table('gaza_locations')
            ->select(['id', 'level', 'source_code', 'name', 'parent_id', 'landmark_type_code', 'landmark_type_name'])
            ->get();
        $keyById = $rows->mapWithKeys(fn ($r) => [$r->id => $r->level.':'.$r->source_code]);

        $existing = [];
        foreach ($rows as $r) {
            $existing[$r->level.':'.$r->source_code] = [
                'name' => $r->name,
                'parent_key' => $r->parent_id === null ? null : $keyById[$r->parent_id],
                'landmark_type_code' => $r->landmark_type_code,
                'landmark_type_name' => $r->landmark_type_name,
            ];
        }

        return $existing;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $existing
     */
    private function refuseRevisions(array $nodes, array $existing): void
    {
        $changes = [];
        foreach (array_intersect_key($nodes, $existing) as $key => $node) {
            foreach (['name', 'parent_key', 'landmark_type_code', 'landmark_type_name'] as $field) {
                if ($node[$field] !== $existing[$key][$field]) {
                    $changes[] = sprintf('%s: %s would change from %s to %s (source row %d).',
                        $key, $field, json_encode($existing[$key][$field], JSON_UNESCAPED_UNICODE),
                        json_encode($node[$field], JSON_UNESCAPED_UNICODE), $node['rows'][0]);
                }
            }
        }
        if ($changes !== []) {
            throw new GazaGeographyImportException(
                GazaGeographyImportException::REVISION_CONFLICT,
                'The source changes records that are already imported. Nothing was written; applying a source revision needs explicit authorization.',
                $changes,
            );
        }
    }

    /**
     * Inserts the records not imported yet, parents first.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $existing
     * @return array<string, int> created per level
     */
    private function insertNew(array $nodes, array $existing): array
    {
        $now = Carbon::now();
        $created = $this->countByLevel([]);
        foreach (GazaLocationLevel::ordered() as $level) {
            $rows = [];
            foreach ($nodes as $key => $node) {
                if ($node['level'] !== $level || isset($existing[$key])) {
                    continue;
                }
                [$parentLevel, $parentCode] = $node['parent_key'] === null ? [null, null] : explode(':', $node['parent_key'], 2);
                $rows[] = [
                    'id' => GazaLocation::idFor($level, $node['source_code']),
                    'level' => $level->value,
                    'source_code' => $node['source_code'],
                    'name' => $node['name'],
                    'parent_id' => $parentLevel === null ? null : GazaLocation::idFor(GazaLocationLevel::from($parentLevel), $parentCode),
                    'parent_level' => $parentLevel,
                    'landmark_type_code' => $node['landmark_type_code'],
                    'landmark_type_name' => $node['landmark_type_name'],
                    'is_active' => true,
                    'sort_order' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('gaza_locations')->insert($chunk);
            }
            $created[$level->value] = count($rows);
        }

        return $created;
    }

    /**
     * Review findings for this source: the objective rules plus the audited
     * judgements of the source definition.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, array{issue: GazaLocationReviewIssue, key: string, notes: string}> keyed "key|issue"
     */
    private function findings(array $nodes, GazaGeographySource $source): array
    {
        $findings = [];
        $add = function (string $key, GazaLocationReviewIssue $issue, string $note) use (&$findings, $nodes): void {
            $findings[$key.'|'.$issue->value] = [
                'issue' => $issue,
                'key' => $key,
                'notes' => $note.' Source rows: '.$this->rowList($nodes[$key]['rows']).'.',
            ];
        };

        // W4: whitespace kept as supplied; display trimming only (G5).
        foreach ($nodes as $key => $node) {
            if (preg_match('/^\s|\s$|\s{2}/u', $node['name']) === 1) {
                $add($key, GazaLocationReviewIssue::NAME_WHITESPACE, 'Source name has leading, trailing or doubled spaces; stored as supplied.');
            }
        }

        // W3: siblings (not landmarks) whose names match once whitespace is ignored.
        // W9: landmarks of one neighborhood with the same type and trimmed name.
        $groups = [];
        foreach ($nodes as $key => $node) {
            $groupKey = $node['level'] === GazaLocationLevel::LANDMARK
                ? 'W9|'.$node['parent_key'].'|'.$node['landmark_type_code'].'|'.trim($node['name'])
                : 'W3|'.$node['parent_key'].'|'.$node['level']->value.'|'.preg_replace('/\s+/u', '', $node['name']);
            $groups[$groupKey][] = $key;
        }
        foreach ($groups as $groupKey => $keys) {
            if (count($keys) < 2) {
                continue;
            }
            $issue = str_starts_with($groupKey, 'W9|')
                ? GazaLocationReviewIssue::POSSIBLE_DUPLICATE_LANDMARK
                : GazaLocationReviewIssue::SAME_PARENT_SIMILAR_NAME;
            foreach ($keys as $key) {
                $others = array_map(fn ($k) => explode(':', $k, 2)[1], array_values(array_diff($keys, [$key])));
                $add($key, $issue, 'Same parent and same name (ignoring spaces) as source code(s) '.implode(', ', $others).'; kept as a distinct record.');
            }
        }

        // W1 / W2: audited judgements; each must name a record of this source.
        $missing = [];
        foreach ($source->auditedFindings as $finding) {
            $key = $finding['level']->value.':'.$finding['source_code'];
            if (! isset($nodes[$key])) {
                $missing[] = "Audited finding {$finding['issue']->value} names {$key}, which is not in the source.";

                continue;
            }
            $add($key, $finding['issue'], $finding['note']);
        }
        if ($missing !== []) {
            throw new GazaGeographyImportException(
                GazaGeographyImportException::SOURCE_CONFLICT,
                'The audited findings do not match this source; nothing was imported.',
                $missing,
            );
        }

        return $findings;
    }

    /**
     * Creates the flags not recorded yet; existing flags are left untouched.
     *
     * @param  array<string, array{issue: GazaLocationReviewIssue, key: string, notes: string}>  $findings
     * @return array{flags_created: array<string, int>, flags_existing: array<string, int>}
     */
    private function recordFlags(array $findings): array
    {
        $issues = array_fill_keys(array_map(fn ($i) => $i->value, GazaLocationReviewIssue::cases()), 0);
        $created = $issues;
        $existing = $issues;

        $recorded = DB::table('gaza_location_review_flags')
            ->get(['gaza_location_id', 'issue_code'])
            ->mapWithKeys(fn ($f) => [$f->gaza_location_id.'|'.$f->issue_code => true]);

        $now = Carbon::now();
        $rows = [];
        foreach ($findings as $finding) {
            [$level, $code] = explode(':', $finding['key'], 2);
            $locationId = GazaLocation::idFor(GazaLocationLevel::from($level), $code);
            $issue = $finding['issue'];
            if (isset($recorded[$locationId.'|'.$issue->value])) {
                $existing[$issue->value]++;

                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'gaza_location_id' => $locationId,
                'issue_code' => $issue->value,
                'severity' => $issue->severity()->value,
                'source_reference' => $issue->sourceReference(),
                'review_status' => GazaLocationReviewStatus::OPEN->value,
                'notes' => $finding['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $created[$issue->value]++;
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('gaza_location_review_flags')->insert($chunk);
        }

        return ['flags_created' => $created, 'flags_existing' => $existing];
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, int>
     */
    private function countByLevel(array $nodes): array
    {
        $counts = array_fill_keys(array_map(fn ($l) => $l->value, GazaLocationLevel::ordered()), 0);
        foreach ($nodes as $node) {
            $counts[$node['level']->value]++;
        }

        return $counts;
    }

    /** @param list<int> $rows */
    private function rowList(array $rows): string
    {
        $shown = implode(', ', array_slice($rows, 0, 10));

        return count($rows) > 10 ? $shown.' … ('.count($rows).' rows)' : $shown;
    }
}
