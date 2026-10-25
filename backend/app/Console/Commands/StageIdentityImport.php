<?php

namespace App\Console\Commands;

use App\Actions\PublishIdentityReleaseAction;
use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The identity reference import entry point (FB-CIVIL-01A P2 + P3). Artisan
 * only: no queue, no HTTP, no unattended activation.
 *
 *   <directory>                      validate and stage an export (P2); re-run to resume
 *   --abort                          close the RUNNING run (abandoning its BUILDING release) and purge staging
 *   --validate --run=<uuid>          release-level checks, quarantine and warnings (read-only)
 *   --preview  --run=<uuid>          NEW / CHANGED / UNCHANGED / carried-forward counts (read-only)
 *   --prepare  --run=<uuid>          build the candidate release under --policy (resumable)
 *   --verify   --release=<id>        database-side proof that the release equals its classification
 *   --publish  --release=<id>        activate a VERIFIED release (asks for confirmation; --yes to confirm)
 *   --status                         runs, releases and the active release (read-only)
 *
 * Production (FB-CIVIL-01A-P4): refused unless the operator's shell carries
 * an explicit approval reference — IDENTITY_REF_PRODUCTION_APPROVAL, e.g. the
 * change ticket — which is logged with every run. Never put it in .env. In
 * production every mode that writes (staging, abort, prepare, verify,
 * publish) also asks for confirmation (--yes to confirm non-interactively);
 * validate, preview and status are read-only. Output: ids, statuses, counts,
 * codes and timings only — never a value from the source.
 */
class StageIdentityImport extends Command
{
    protected $signature = 'famboook:identity-ref-stage
        {directory? : Export directory holding manifest.json and the CSV files (staging)}
        {--target= : Name of the database you intend to work on (must equal the connected database)}
        {--connection=identity_ref_import : Database connection of a famboook_identity_writer login}
        {--expected-files= : Approved number of files (e.g. 24)}
        {--expected-rows= : Approved total number of rows (e.g. 2399891)}
        {--chunk='.StageIdentityImportAction::DEFAULT_CHUNK_SIZE.' : Rows per committed chunk (staging and preparation)}
        {--abort : Close the RUNNING run as FAILED and purge the staging of finished runs}
        {--validate : Validate a staged run (read-only)}
        {--preview : Preview comparison counts of a staged run (read-only)}
        {--prepare : Prepare the candidate release of a staged run}
        {--verify : Verify a prepared release}
        {--publish : Publish a verified release}
        {--status : Show runs, releases and the active release}
        {--run= : Import run id (uuid)}
        {--release= : Release id}
        {--policy='.IdentityImportPolicy::DEFAULT_ID.' : Approved import policy id}
        {--yes : Confirm the operation without the interactive question}';

    protected $description = 'Stage, validate, prepare, verify and publish identity reference releases (FB-CIVIL-01A).';

    private const MODES = ['abort', 'validate', 'preview', 'prepare', 'verify', 'publish', 'status'];

    private const READ_ONLY_MODES = ['validate', 'preview', 'status'];

    /** Shell variable carrying the Production approval reference (read from the process environment, not from config). */
    public const PRODUCTION_APPROVAL_VARIABLE = 'IDENTITY_REF_PRODUCTION_APPROVAL';

    public function handle(StageIdentityImportAction $stager, PublishIdentityReleaseAction $releases): int
    {
        $modes = array_values(array_filter(self::MODES, fn ($m) => (bool) $this->option($m)));
        if (count($modes) > 1) {
            $this->error('[INVALID_USAGE] Choose one operation: --'.implode(', --', self::MODES).', or a directory to stage.');

            return self::FAILURE;
        }
        $mode = $modes[0] ?? 'stage';
        if (app()->environment('production')) {
            $approval = (string) getenv(self::PRODUCTION_APPROVAL_VARIABLE);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,63}$/', $approval) !== 1) {
                $this->error('[NOT_APPROVED] Production use needs an approval reference in '.self::PRODUCTION_APPROVAL_VARIABLE.' (operator shell only).');

                return self::FAILURE;
            }
            Log::channel(config('logging.default'))->info('identity_ref.production_run', ['mode' => $mode, 'approval' => $approval]);
            $this->line("PRODUCTION — approval {$approval}");
            if (! in_array($mode, self::READ_ONLY_MODES, true) && $mode !== 'publish'
                && ! $this->option('yes') && ! $this->confirm("Run '{$mode}' on the PRODUCTION database {$this->option('target')}?", false)) {
                $this->error('[NOT_CONFIRMED] Not confirmed; nothing changed.');

                return self::FAILURE;
            }
        }
        $connection = (string) $this->option('connection');
        // Credentials from the operator's shell at run time — never from the cached configuration.
        $username = (string) getenv('IDENTITY_REF_DB_USERNAME');
        if ($username !== '' && config("database.connections.{$connection}") !== null) {
            config(["database.connections.{$connection}.username" => $username,
                "database.connections.{$connection}.password" => (string) getenv('IDENTITY_REF_DB_PASSWORD')]);
            DB::purge($connection);
        }
        if (config("database.connections.{$connection}.username") === null) {
            $this->error("[NOT_CONFIGURED] Connection {$connection} has no login (export IDENTITY_REF_DB_USERNAME and IDENTITY_REF_DB_PASSWORD in the operator shell).");

            return self::FAILURE;
        }
        $target = (string) $this->option('target');
        $db = DB::connection($connection);
        $logger = Log::channel(config('logging.default'));

        try {
            return match ($mode) {
                'stage' => $this->stage($stager, $db, $target, $logger),
                'abort' => $this->abort($stager, $db, $target, $logger),
                'validate', 'preview' => $this->assess($releases, $db, $target, $modes[0]),
                'prepare' => $this->prepare($releases, $db, $target, $logger),
                'verify' => $this->verify($releases, $db, $target, $logger),
                'publish' => $this->publish($releases, $db, $target, $logger),
                'status' => $this->status($releases, $db, $target),
            };
        } catch (IdentityImportException $e) {
            $this->error($e->getMessage());
            foreach (array_slice($e->problems, 0, 50) as $problem) {
                $this->line('  - '.$problem);
            }
            if (count($e->problems) > 50) {
                $this->line('  … '.(count($e->problems) - 50).' more (see identity_ref.import_issues)');
            }
            if ($e->runId !== null) {
                $this->line('Import run: '.$e->runId);
            }
            if ($e->releaseId !== null) {
                $this->line('Release: '.$e->releaseId);
            }

            return self::FAILURE;
        } finally {
            DB::purge($connection);
        }
    }

    private function stage(StageIdentityImportAction $stager, $db, string $target, $logger): int
    {
        $directory = (string) $this->argument('directory');
        if ($directory === '') {
            $this->error('[MANIFEST_INVALID] An export directory (or an operation option) is required.');

            return self::FAILURE;
        }
        $report = $stager->handle($db, $directory, [
            'target' => $target,
            'expected_files' => $this->option('expected-files') !== null ? (int) $this->option('expected-files') : null,
            'expected_rows' => $this->option('expected-rows') !== null ? (int) $this->option('expected-rows') : null,
            'chunk_size' => (int) $this->option('chunk'),
        ], $logger);

        $this->line('Import run: '.$report['run_id'].($report['resumed'] ? ' (resumed)' : ''));
        $this->table(['Measure', 'Value'], [
            ['Files', $report['files']],
            ['Files staged now', $report['files_staged_now']],
            ['Files reset (checkpoint mismatch)', $report['files_reset']],
            ['Chunks verified (resume)', $report['chunks_verified']],
            ['Chunks loaded now', $report['chunks_loaded']],
            ['Rows loaded now', $report['rows_loaded_now']],
            ['Rows staged (run total)', $report['rows_staged']],
        ]);
        $this->codeTable('Issue code', $report['issues']);
        $this->info('Staged; awaiting publication (P3). Nothing was published.');

        return self::SUCCESS;
    }

    private function abort(StageIdentityImportAction $stager, $db, string $target, $logger): int
    {
        $result = $stager->abort($db, $target, $logger);
        $this->line('Aborted run: '.($result['aborted_run'] ?? 'none'));
        $this->line('Abandoned release: '.($result['abandoned_release'] ?? 'none'));
        $this->line('Staging rows purged: '.$result['rows_purged']);

        return self::SUCCESS;
    }

    private function assess(PublishIdentityReleaseAction $releases, $db, string $target, string $mode): int
    {
        $report = $releases->assess($db, $this->requiredRun(), $this->policy(), $target);
        $this->line('Import run: '.$report['run_id'].' — base release: '.($report['base_release'] ?? 'none (first release)'));
        if ($mode === 'validate') {
            $this->codeList('Fatal (release-level)', $report['fatal']);
            $this->codeList('Blocked by policy', $report['blocked']);
            $this->codeTable('Quarantine (row-level conflict)', $report['conflicts']);
            $this->codeTable('Warning (informational)', $report['warnings']);
        } else {
            $this->countTable($report['counts']);
        }
        $report['publishable']
            ? $this->info('Can be prepared under policy '.$this->option('policy').'.')
            : $this->warn('Cannot be prepared under policy '.$this->option('policy').'.');

        return $report['publishable'] ? self::SUCCESS : self::FAILURE;
    }

    private function prepare(PublishIdentityReleaseAction $releases, $db, string $target, $logger): int
    {
        $report = $releases->prepare($db, $this->requiredRun(), $this->policy(), $target, (int) $this->option('chunk'), $logger);
        $this->releaseLines($report);
        $this->info('Prepared (not visible to readers). Next: --verify --release='.$report['release_id']);

        return self::SUCCESS;
    }

    private function verify(PublishIdentityReleaseAction $releases, $db, string $target, $logger): int
    {
        $report = $releases->verify($db, $this->requiredRelease(), $target, $logger);
        $this->releaseLines($report);
        $this->info('Verified. Next: --publish --release='.$report['release_id']);

        return self::SUCCESS;
    }

    private function publish(PublishIdentityReleaseAction $releases, $db, string $target, $logger): int
    {
        $release = $this->requiredRelease();
        if (! $this->option('yes') && ! $this->confirm("Activate identity reference release {$release} on database {$target}?", false)) {
            throw new IdentityImportException(IdentityImportException::NOT_CONFIRMED, 'Publication was not confirmed; nothing changed.');
        }
        $report = $releases->publish($db, $release, $target, $logger);
        $this->releaseLines($report);
        $this->info($report['outcome'] === 'ALREADY_PUBLISHED'
            ? 'Already published; nothing changed.'
            : "Published in {$report['activation_seconds']} s. Active release: {$report['active_release']}.");

        return self::SUCCESS;
    }

    private function status(PublishIdentityReleaseAction $releases, $db, string $target): int
    {
        $status = $releases->status($db, $target);
        $this->line('Active release: '.($status['active_release'] ?? 'none'));
        $this->table(['Run', 'Status', 'Error', 'Expected', 'Staged', 'Issues'],
            array_map(fn ($r) => [$r['id'], $r['status'], $r['error_code'], $r['expected_rows'], $r['rows_loaded'], $r['issues']], $status['runs']));
        $this->table(['Release', 'Run', 'Status', 'Base', 'Records', 'Preparation', 'Policy', 'New', 'Changed', 'Unchanged', 'Conflict', 'Carried'],
            array_map(fn ($r) => [$r['id'], $r['run_id'], $r['status'], $r['base_release_id'], $r['record_count'], $r['preparation'], $r['policy_id'],
                $r['new_count'], $r['changed_count'], $r['unchanged_count'], $r['conflict_count'], $r['carried_forward_count']], $status['releases']));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function releaseLines(array $report): void
    {
        $this->line("Release {$report['release_id']} (run {$report['run_id']}): {$report['release_status']}, preparation {$report['preparation']}, policy {$report['policy_id']}");
        $this->line('Base release: '.($report['base_release'] ?? 'none').' — active release: '.($report['active_release'] ?? 'none'));
        if ($report['counts'] !== null) {
            $this->countTable($report['counts']);
        }
        $this->codeTable('Warning (informational)', $report['warnings']);
    }

    /** @param array<string, int>|null $counts */
    private function countTable(?array $counts): void
    {
        if ($counts === null) {
            $this->line('No comparison (fatal validation failure).');

            return;
        }
        $this->table(['Comparison', 'Rows'], array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts));
    }

    /** @param array<string, int> $codes */
    private function codeTable(string $title, array $codes): void
    {
        if ($codes !== []) {
            $this->table([$title, 'Rows'], array_map(fn ($c, $n) => [$c, $n], array_keys($codes), $codes));
        }
    }

    /** @param list<string> $codes */
    private function codeList(string $title, array $codes): void
    {
        $this->line($title.': '.($codes === [] ? 'none' : implode(', ', $codes)));
    }

    private function policy(): IdentityImportPolicy
    {
        try {
            return IdentityImportPolicy::approved((string) $this->option('policy'));
        } catch (InvalidArgumentException) {
            throw new IdentityImportException(IdentityImportException::POLICY_NOT_APPROVED, 'Unknown or unapproved policy; approved: '.implode(', ', IdentityImportPolicy::approvedIds()));
        }
    }

    private function requiredRun(): string
    {
        $run = (string) $this->option('run');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $run) !== 1) {
            throw new IdentityImportException(IdentityImportException::RELEASE_STATE_INVALID, 'An explicit --run=<uuid> is required.');
        }

        return $run;
    }

    private function requiredRelease(): int
    {
        $release = (string) $this->option('release');
        if (preg_match('/^[1-9][0-9]{0,18}$/', $release) !== 1) {
            throw new IdentityImportException(IdentityImportException::RELEASE_STATE_INVALID, 'An explicit --release=<id> is required.');
        }

        return (int) $release;
    }
}
