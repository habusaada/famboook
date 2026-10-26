<?php

namespace App\Console\Commands;

use App\Actions\PublishIdentityReleaseAction;
use App\Actions\StageIdentityImportAction;
use App\Support\CivilImport\CivilImportSourceCatalogue;
use App\Support\CivilImport\CivilImportWorkerException;
use App\Support\CivilImport\IdentityImportWorker;
use App\Support\IdentityRef\IdentitySourceVerifier;
use Illuminate\Console\Command;

/**
 * The civil identity import worker loop (FB-CIVIL-01A-UI U2, ADR §4). Runs
 * only through the dedicated entry point `identity-import-worker` (no
 * application .env, no cached configuration); refused under plain artisan.
 * One request at a time, 30 s idle poll, heartbeat every 30 s. SIGTERM /
 * SIGINT stop it after the current request (a running FB-CIVIL-01A operation
 * is resumable in any case).
 *
 *   --once        one iteration (catalogue, at most one request), then exit
 *   --self-check  print the process boundary (no database access) and exit
 */
class IdentityImportWorkerCommand extends Command
{
    protected $signature = 'famboook:identity-ref-worker
        {--once : Run one iteration and exit}
        {--instance= : Worker instance name (default: worker-<hostname>)}
        {--self-check : Print the process boundary and exit}';

    protected $description = 'Execute civil identity import requests (FB-CIVIL-01A-UI U2; dedicated worker entry point only).';

    private bool $stopping = false;

    public function handle(): int
    {
        if (! defined('FAMBOOOK_IDENTITY_IMPORT_WORKER')) {
            $this->error('[WORKER_ENTRYPOINT_REQUIRED] Run the worker through backend/identity-import-worker, never plain artisan.');

            return self::FAILURE;
        }
        if ($this->option('self-check')) {
            return $this->selfCheck();
        }

        $instance = (string) ($this->option('instance') ?: 'worker-'.preg_replace('/[^A-Za-z0-9._-]/', '', (string) gethostname()));
        $instance = substr($instance, 0, 64);
        $worker = new IdentityImportWorker(app(StageIdentityImportAction::class), app(PublishIdentityReleaseAction::class),
            app(IdentitySourceVerifier::class), CivilImportSourceCatalogue::fromConfig(), $instance);
        try {
            $worker->boot();
        } catch (CivilImportWorkerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }
        $this->line("civil import worker {$instance} started");
        do {
            $request = $worker->runOnce();
            if ($request !== null) {
                $this->line("request {$request->id} {$request->type} {$request->status}".($request->reason_code ? " {$request->reason_code}" : ''));
            } elseif (! $this->option('once')) {
                for ($i = 0; $i < (int) config('civil_import.worker.idle_poll_seconds', 30) && ! $this->stopping; $i++) {
                    sleep(1);
                }
            }
        } while (! $this->option('once') && ! $this->stopping);
        $worker->stop();

        return self::SUCCESS;
    }

    /** What this process can see — booleans and names only, never a value. */
    private function selfCheck(): int
    {
        $this->line((string) json_encode([
            'entrypoint' => true,
            'environment_file_loaded' => is_file(app()->environmentFilePath()),
            'configuration_cached' => app()->configurationIsCached(),
            'app_key_present' => (string) config('app.key') !== '',
            'control_login_configured' => (string) config('database.connections.'.config('database.default').'.username') !== '',
            'import_login_configured' => (string) getenv('IDENTITY_REF_DB_USERNAME') !== '',
            'publication_enabled' => config('civil_import.publish_enabled') === true,
        ]));

        return self::SUCCESS;
    }
}
