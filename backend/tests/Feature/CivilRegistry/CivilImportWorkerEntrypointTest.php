<?php

namespace Tests\Feature\CivilRegistry;

use App\Console\Commands\StageIdentityImport;
use App\Support\IdentityRef\IdentityRefProductionGate;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U2 — the worker's credential boundary (ADR §4, D-UI-10):
 * the dedicated entry point boots WITHOUT the application .env and WITHOUT
 * the cached configuration, runs one command only, and the worker command
 * refuses plain artisan. Real subprocesses with a minimal environment; no
 * database. Plus the shared Production gate (X-7).
 */
class CivilImportWorkerEntrypointTest extends TestCase
{
    /** A minimal process environment: what systemd would pass, minus any secret. */
    private function spawn(array $command, array $env = []): Process
    {
        // Symfony Process inherits the parent environment (here: phpunit's, with APP_KEY etc.):
        // remove every inherited variable, then pass only the minimum.
        $base = array_map(fn () => false, getenv());
        foreach (['PATH', 'Path', 'SystemRoot', 'TEMP', 'TMP'] as $keep) {
            if (getenv($keep) !== false) {
                $base[$keep] = getenv($keep);
            }
        }
        $process = new Process($command, base_path(), $env + $base);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    public function test_the_entrypoint_never_loads_the_application_env_or_cached_configuration(): void
    {
        $this->assertFileExists(base_path('identity-import-worker'));
        $process = $this->spawn([PHP_BINARY, 'identity-import-worker', 'famboook:identity-ref-worker', '--self-check'],
            ['IDENTITY_REF_DB_USERNAME' => 'famboook_identity_import_test']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['entrypoint']);
        $this->assertFalse($report['environment_file_loaded'], 'the application .env must not be read');
        $this->assertFalse($report['configuration_cached'], 'the cached configuration must not be used');
        $this->assertFalse($report['app_key_present'], 'nothing from the application .env reached the worker');
        $this->assertFalse($report['control_login_configured']);
        $this->assertTrue($report['import_login_configured']);
        $this->assertFalse($report['publication_enabled']);

        // Positive control: what the unit's EnvironmentFile provides IS seen.
        $control = $this->spawn([PHP_BINARY, 'identity-import-worker', 'famboook:identity-ref-worker', '--self-check'],
            ['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'DB_CONNECTION' => 'pgsql', 'DB_USERNAME' => 'famboook_civil_control_login']);
        $seen = json_decode(trim($control->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($seen['app_key_present']);
        $this->assertTrue($seen['control_login_configured']);
    }

    public function test_the_entrypoint_runs_the_worker_command_only_and_artisan_cannot_run_the_worker(): void
    {
        $other = $this->spawn([PHP_BINARY, 'identity-import-worker', 'migrate:status']);
        $this->assertSame(2, $other->getExitCode());
        $this->assertStringContainsString('WORKER_COMMAND_ONLY', $other->getErrorOutput());

        $this->assertSame(1, Artisan::call('famboook:identity-ref-worker', ['--once' => true]));
        $this->assertStringContainsString('WORKER_ENTRYPOINT_REQUIRED', Artisan::output());
    }

    public function test_the_unit_template_runs_the_entrypoint_as_a_non_root_account_with_its_own_credential_file(): void
    {
        $unit = (string) file_get_contents(base_path('../deploy/systemd/famboook-identity-import-worker.service'));
        $settings = [];
        foreach (preg_split('/\R/', $unit) as $line) {
            if ($line !== '' && $line[0] !== '#' && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $settings[$key] = $value;
            }
        }
        $this->assertSame('famboook-import', $settings['User']);
        $this->assertNotSame('root', $settings['Group']);
        $this->assertSame('/etc/famboook/identity-import-worker.env', $settings['EnvironmentFile']);
        $this->assertStringContainsString('identity-import-worker famboook:identity-ref-worker', $settings['ExecStart']);
        $this->assertStringNotContainsString('artisan', $settings['ExecStart']);
        $this->assertSame('strict', $settings['ProtectSystem']);
        $this->assertSame('true', $settings['NoNewPrivileges']);
        $this->assertStringContainsString('backend/.env', $settings['InaccessiblePaths']);
        $this->assertStringContainsString('bootstrap/cache/config.php', $settings['InaccessiblePaths']);
        $this->assertArrayNotHasKey('ReadWritePaths', $settings, 'the worker writes nothing in the application');

        $example = (string) file_get_contents(base_path('../deploy/env/identity-import-worker.env.example'));
        $this->assertDoesNotMatchRegularExpression('/^(DB_PASSWORD|IDENTITY_REF_DB_PASSWORD)=(?!<set on the server>)/m', $example);
        $this->assertMatchesRegularExpression('/^CIVIL_IMPORT_PUBLISH_ENABLED=false$/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^IDENTITY_REF_PRODUCTION_APPROVAL=/m', $example, 'no approval reference by default (S-4)');
        $this->assertDoesNotMatchRegularExpression('/^DB_USERNAME=famboook_identity/m', $example, 'the control login is not an identity login');
    }

    public function test_the_production_gate_is_shared_and_needs_an_approval_reference_in_production(): void
    {
        putenv(IdentityRefProductionGate::VARIABLE);
        $this->assertTrue(IdentityRefProductionGate::allows());   // testing environment
        $this->app['env'] = 'production';
        try {
            $this->assertFalse(IdentityRefProductionGate::allows());
            putenv(IdentityRefProductionGate::VARIABLE.'=bad value!');
            $this->assertFalse(IdentityRefProductionGate::allows());
            putenv(IdentityRefProductionGate::VARIABLE.'=CHG-2026-0001');
            $this->assertTrue(IdentityRefProductionGate::allows());
            $this->assertSame('CHG-2026-0001', IdentityRefProductionGate::reference());
        } finally {
            putenv(IdentityRefProductionGate::VARIABLE);
            $this->app['env'] = 'testing';
        }
        $this->assertSame(IdentityRefProductionGate::VARIABLE, StageIdentityImport::PRODUCTION_APPROVAL_VARIABLE);
    }
}
