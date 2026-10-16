<?php

namespace App\Console\Commands;

use App\Actions\ImportGazaGeographyAction;
use App\Exceptions\GazaGeographyImportException;
use App\Support\Geography\GazaGeographySource;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Imports the official Gaza geographic workbook (FB-GEO-01 Stage 1C,
 * ADR-FB-GEO-01 §3.3). Deliberately NOT part of DatabaseSeeder: it runs only
 * when invoked, against a workbook kept outside Git whose SHA-256 must match
 * the audited version. Idempotent; never deletes, never reactivates, never
 * changes an imported record and never touches families or residences.
 * Every executed or refused attempt is recorded in gaza_geography_import_runs
 * (except a missing file and --dry-run). An unexpected error is rethrown
 * after its run is closed as FAILED. Production use needs explicit approval
 * (and confirmation here).
 */
class ImportGazaGeography extends Command
{
    use ConfirmableTrait;

    protected $signature = 'famboook:import-gaza-geography
        {--path= : Workbook path (default: data/references/gaza-geographic-classification.xlsx beside backend/)}
        {--dry-run : Validate and report without writing anything}
        {--force : Skip the production confirmation}';

    protected $description = 'Import the official Gaza geographic reference workbook (checksum-verified, idempotent).';

    public function handle(ImportGazaGeographyAction $action): int
    {
        if (! $this->option('dry-run') && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $source = GazaGeographySource::official($this->option('path') ?: null);
        $this->line('Source: '.$source->path);
        $this->line('Authority: '.GazaGeographySource::OFFICIAL_AUTHORITY.' (department, release date, licence and version unverified)');

        try {
            $report = $action->handle($source, (bool) $this->option('dry-run'));
        } catch (GazaGeographyImportException $e) {
            $this->error("[{$e->reason}] {$e->getMessage()}");
            foreach ($e->problems as $problem) {
                $this->line('  - '.$problem);
            }
            $this->line($e->runId === null
                ? 'Not recorded as an import run (missing file or dry run).'
                : "Recorded as REJECTED import run {$e->runId}.");

            return self::FAILURE;
        }

        if ($report['run_id'] !== null) {
            $this->line('Import run: '.$report['run_id'].' (SUCCEEDED)');
        }
        $this->line('SHA-256: '.$report['checksum'].' (verified)');
        $this->table(
            ['Level', 'In source', 'Created', 'Already imported'],
            array_map(fn (string $level) => [$level, $report['source'][$level], $report['created'][$level], $report['unchanged'][$level]], array_keys($report['source'])),
        );
        $this->line('Total in source: '.array_sum($report['source']).', created: '.array_sum($report['created']));
        $this->table(
            ['Review issue', 'Flags created', 'Already flagged'],
            array_map(fn (string $issue) => [$issue, $report['flags_created'][$issue], $report['flags_existing'][$issue]], array_keys($report['flags_created'])),
        );
        if ($report['missing_from_source'] !== []) {
            $this->warn(count($report['missing_from_source']).' imported record(s) are absent from this source; kept, not deleted:');
            foreach ($report['missing_from_source'] as $key) {
                $this->line('  - '.$key);
            }
        }
        $this->info($report['dry_run'] ? 'Dry run: nothing was written.' : 'Import complete.');

        return self::SUCCESS;
    }
}
