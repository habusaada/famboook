<?php

namespace Tests\Feature\Deployment;

use App\Jobs\NotificationHeartbeatJob;
use App\Support\Notifications\NotificationConfig;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.1: the notification operations templates in deploy/ agree
 * with the code — the worker's queue, tries and timeout, the timing
 * invariant (timeout < retry_after < stale threshold) and its hardening; the
 * systemd scheduler (oneshot service + every-minute timer without catch-up,
 * the ONLY scheduler mechanism: no cron template, decision O2); the env
 * template's safe defaults, the deployment order, the documented list of
 * scheduled tasks and the inventory of queued jobs. Static: it reads files
 * only, starts nothing and touches no database or queue.
 */
class NotificationOperationsTemplatesTest extends TestCase
{
    private function deploy(string $path): string
    {
        $file = base_path('../deploy/'.$path);
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** Non-comment, non-blank lines. @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
    }

    private function assertNoSecrets(string $text): void
    {
        $this->assertDoesNotMatchRegularExpression('/(PASSWORD|APP_KEY|API_KEY|SECRET|TOKEN)\s*=\s*\S/i', $text);
    }

    public function test_the_worker_unit_runs_the_notifications_queue_once_within_the_timing_invariant(): void
    {
        $unit = $this->deploy('systemd/famboook-notifications-worker.service');
        $lines = $this->lines($unit);

        // systemd syntax: section headers or Key=Value only, the three sections present.
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^(\[(Unit|Service|Install)\]|[A-Za-z]+=.+)$/', $line, $line);
        }
        foreach (['[Unit]', '[Service]', '[Install]'] as $section) {
            $this->assertContains($section, $lines);
        }

        $settings = [];
        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $settings[$key] = $value;
            }
        }
        $this->assertSame('www-data', $settings['User']);
        $this->assertNotSame('root', $settings['Group']);
        $this->assertSame('/var/www/famboook/backend', $settings['WorkingDirectory']);
        $this->assertSame('always', $settings['Restart']);
        // FB-NOTIFY-01 consolidation (O1): the hardening of the former proposal, same command.
        $this->assertSame('0007', $settings['UMask'], 'group-writable, never world-accessible (docs/08 §10)');
        $this->assertGreaterThan(0, (int) $settings['StartLimitBurst'], 'a crash loop stops');
        foreach (['NoNewPrivileges', 'PrivateTmp', 'PrivateDevices', 'ProtectHome', 'ProtectKernelTunables', 'ProtectKernelModules', 'ProtectControlGroups', 'RestrictSUIDSGID', 'RestrictRealtime', 'LockPersonality'] as $flag) {
            $this->assertSame('true', $settings[$flag], $flag);
        }
        $this->assertSame('full', $settings['ProtectSystem']);
        $this->assertSame('/var/www/famboook/backend/storage /var/www/famboook/backend/bootstrap/cache', $settings['ReadWritePaths']);
        $this->assertArrayNotHasKey('KillMode', $settings, 'stop semantics unchanged');

        $exec = $settings['ExecStart'];
        $this->assertStringContainsString('artisan queue:work database', $exec);
        $this->assertStringContainsString('--queue='.config('notifications.queue'), $exec);
        $this->assertSame(NotificationConfig::DEFAULT_QUEUE, config('notifications.queue'));
        $this->assertStringContainsString('--tries=1', $exec);
        $this->assertStringNotContainsString('--force', $exec, 'the worker must pause while the application is down');

        preg_match('/--timeout=(\d+)/', $exec, $timeout);
        $timeout = (int) $timeout[1];
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $stale = (int) config('notifications.stale_publishing_seconds');
        $this->assertSame(NotificationConfig::PUBLICATION_JOB_TIMEOUT_SECONDS, $timeout);
        $this->assertSame([60, 90, 600], [$timeout, $retryAfter, $stale]);
        $this->assertTrue($timeout < $retryAfter && $retryAfter < $stale, 'timeout < retry_after < stale');
        $this->assertGreaterThan($timeout, (int) $settings['TimeoutStopSec'], 'a running publication may finish on stop');

        $this->assertNoSecrets($unit);
    }

    /** @return array<string, array<string, string>> the unit's settings per section */
    private function sections(string $path): array
    {
        $text = $this->deploy($path);
        $this->assertNoSecrets($text);
        $this->assertStringEndsWith("\n", $text, "{$path}: trailing newline");
        $parsed = [];
        $current = null;
        foreach ($this->lines($text) as $line) {
            if (preg_match('/^\[([A-Za-z]+)\]$/', $line, $m)) {
                $current = $m[1];
                $parsed[$current] = [];

                continue;
            }
            $this->assertMatchesRegularExpression('/^[A-Za-z]+=.+$/', $line, "{$path}: {$line}");
            $this->assertNotNull($current, "{$path}: a setting before any section");
            [$key, $value] = explode('=', $line, 2);
            $this->assertArrayNotHasKey($key, $parsed[$current], "{$path}: {$key} set twice");
            $parsed[$current][$key] = $value;
        }

        return $parsed;
    }

    public function test_the_scheduler_is_a_oneshot_schedule_run_started_every_minute_by_a_timer_without_catch_up(): void
    {
        $service = $this->sections('systemd/famboook-scheduler.service');
        $this->assertSame(['Unit', 'Service'], array_keys($service), 'started by the timer only: no [Install]');
        $run = $service['Service'];
        $this->assertSame('oneshot', $run['Type']);
        $this->assertSame('www-data', $run['User']);
        $this->assertSame('www-data', $run['Group']);
        $this->assertSame('/var/www/famboook/backend', $run['WorkingDirectory']);
        $this->assertSame('0007', $run['UMask']);
        $this->assertMatchesRegularExpression('/^\S+ artisan schedule:run --no-interaction$/', $run['ExecStart']);
        $this->assertArrayNotHasKey('Restart', $run, 'the timer starts it; it never restarts itself');
        $this->assertLessThan(600, (int) $run['TimeoutStartSec'], 'a hung run stops before the 10-minute task locks expire');
        $this->assertSame('journal', $run['StandardOutput']);
        foreach (['NoNewPrivileges', 'PrivateTmp', 'ProtectHome', 'RestrictSUIDSGID'] as $flag) {
            $this->assertSame('true', $run[$flag], $flag);
        }

        $timer = $this->sections('systemd/famboook-scheduler.timer');
        $this->assertSame(['Unit', 'Timer', 'Install'], array_keys($timer));
        $this->assertSame('*-*-* *:*:00', $timer['Timer']['OnCalendar']);
        $this->assertSame('false', $timer['Timer']['Persistent'], 'missed minutes are never caught up');
        $this->assertSame('famboook-scheduler.service', $timer['Timer']['Unit']);
        $this->assertSame('timers.target', $timer['Install']['WantedBy']);
    }

    public function test_there_is_exactly_one_scheduler_mechanism_and_one_worker_template(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('../deploy/cron'), 'no cron scheduler in parallel with the timer (O2)');
        $units = array_map('basename', glob(base_path('../deploy/systemd/*')));
        sort($units);
        // The civil identity import worker (FB-CIVIL-01A-UI U2, ADR §4) is a separate, non-queue unit.
        $this->assertSame(['famboook-identity-import-worker.service', 'famboook-notifications-worker.service', 'famboook-scheduler.service',
            'famboook-scheduler.timer'], $units);
        // The health script watches the one worker unit (O1).
        $this->assertStringContainsString('UNIT="famboook-notifications-worker"', $this->deploy('scripts/notifications-health.sh'));
    }

    public function test_the_scheduler_unit_documents_every_scheduled_task(): void
    {
        $text = $this->deploy('systemd/famboook-scheduler.service');
        $events = app(Schedule::class)->events();
        $this->assertCount(6, $events, 'the six tasks of the readiness document');

        foreach ($events as $event) {
            /** @var Event $event */
            $name = $event instanceof CallbackEvent
                ? ($event->description === NotificationHeartbeatJob::class ? 'NotificationHeartbeatJob' : (string) $event->description)
                : (preg_match('/(famboook:[a-z0-9:-]+)/', (string) $event->command, $m) ? $m[1] : (string) $event->command);
            $this->assertStringContainsString($name, $text, "the scheduler unit must document {$name}");
        }
    }

    public function test_the_only_queued_jobs_are_the_two_notification_jobs(): void
    {
        // A new queued job needs its own worker decision: the worker consumes `notifications` only.
        $queued = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/implements[^{]*\bShouldQueue\b/', (string) file_get_contents($file->getPathname()))) {
                $queued[] = $file->getBasename('.php');
            }
        }
        sort($queued);
        $this->assertSame(['NotificationHeartbeatJob', 'PublishNotificationJob'], $queued);
    }

    public function test_the_env_template_keeps_notifications_off_on_the_sync_queue(): void
    {
        $env = [];
        foreach ($this->lines($this->deploy('env/backend.production.env.example')) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $env[$key] = $value;
        }

        $this->assertSame('OFF', $env['NOTIFICATIONS_MODE']);
        $this->assertSame('', $env['NOTIFICATIONS_PILOT_SENDER_USER_IDS']);
        $this->assertSame('false', $env['NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED']);
        $this->assertSame('false', $env['NOTIFICATIONS_FOLLOWUPS_ENABLED']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION'], 'the template does not switch the queue; the runbook does');
        $this->assertSame('database', $env['CACHE_STORE'], 'a shared, lock-capable cache store');
        $this->assertArrayNotHasKey('NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS', $env);
    }

    public function test_the_backend_deployment_restarts_workers_safely_after_caching_and_starts_nothing(): void
    {
        $script = $this->deploy('scripts/deploy-backend.sh');
        $steps = [
            'php artisan migrate --force',
            'php artisan db:seed --class=RolePermissionSeeder --force',
            'php artisan famboook:verify-permissions',
            'php artisan optimize',
            'php artisan queue:restart',
            'php artisan up',
        ];
        $positions = array_map(fn ($step) => strpos($script, $step), $steps);
        foreach ($positions as $i => $position) {
            $this->assertNotFalse($position, $steps[$i]);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'migrate → seed → verify → optimize → queue:restart → up');

        // queue:restart never fails the deployment; nothing is started or installed.
        $this->assertMatchesRegularExpression('/^php artisan queue:restart \|\| echo /m', $script);
        foreach (['deploy-backend.sh', 'deploy-frontend.sh'] as $name) {
            // Executed lines only: the comments may name what they never do.
            $commands = implode("\n", $this->lines($this->deploy("scripts/{$name}")));
            $this->assertDoesNotMatchRegularExpression('/systemctl (enable|start)|crontab|cron\.d|queue:work|schedule:(run|work)|famboook-scheduler/', $commands, $name);
        }
    }

    /**
     * Runs the real health script with a stubbed `php` (the check's output and
     * exit code) and a stubbed or absent `systemctl`.
     *
     * @return array{0: int, 1: string}
     */
    private function health(string $checkOutput, int $checkExit, ?string $worker): array
    {
        $bash = (new ExecutableFinder)->find('bash');
        if ($bash === null) {
            $this->markTestSkipped('bash is not available on this host.');
        }
        $dir = sys_get_temp_dir().'/famboook-health-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $posix = fn (string $path) => str_replace('\\', '/', $path);
        file_put_contents("{$dir}/check.txt", $checkOutput);
        file_put_contents("{$dir}/php", "#!/usr/bin/env bash\ncat '{$posix($dir)}/check.txt'\nexit {$checkExit}\n");
        file_put_contents("{$dir}/systemctl", "#!/usr/bin/env bash\necho '{$worker}'\n[ '{$worker}' = active ]\n");
        chmod("{$dir}/php", 0755);
        chmod("{$dir}/systemctl", 0755);

        $process = new Process([$bash, $posix(base_path('../deploy/scripts/notifications-health.sh'))], null, [
            'PHP_BIN' => $posix("{$dir}/php"),
            'SYSTEMCTL' => $worker === null ? $posix("{$dir}/no-such-systemctl") : $posix("{$dir}/systemctl"),
        ]);
        $process->run();
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        return [(int) $process->getExitCode(), $process->getOutput()];
    }

    private function check(string $mode): string
    {
        return "Notification mode (effective): {$mode}\nConfiguration: VALID\nWorker heartbeat: FRESH (age 20 s, max 300 s)\n";
    }

    public function test_the_health_script_classifies_every_mode_without_alerting_on_an_intentional_off(): void
    {
        $cases = [
            'off, no worker installed' => [$this->check('OFF')."Notifications: no warnings.\n", 0, 'inactive', 0, 'STATE: NOT_ACTIVATED'],
            'off with a warning' => [$this->check('OFF')."WARN: 2 PUBLISHING message(s) are stale candidates for recovery.\n", 1, 'inactive', 1, 'STATE: DEGRADED'],
            'pilot healthy' => [$this->check('PILOT')."Notifications: no warnings.\n", 0, 'active', 0, 'STATE: HEALTHY'],
            'pilot, worker stopped' => [$this->check('PILOT')."Notifications: no warnings.\n", 0, 'failed', 1, 'STATE: DEGRADED'],
            'pilot, heartbeat stale' => [$this->check('PILOT')."WARN: Notifications are enabled but publication is not ready: HEARTBEAT_STALE.\n", 1, 'active', 1, 'STATE: DEGRADED'],
            'on, no systemctl here' => [$this->check('ON')."Notifications: no warnings.\n", 0, null, 0, 'STATE: HEALTHY'],
            'on, worker inactive' => [$this->check('ON')."Notifications: no warnings.\n", 0, 'inactive', 1, 'STATE: DEGRADED'],
            'the check cannot run' => ["PHP Fatal error: could not connect\n", 255, 'active', 2, 'STATE: UNAVAILABLE'],
            'no mode line' => ["Notifications: no warnings.\n", 0, 'active', 2, 'STATE: UNAVAILABLE'],
        ];

        foreach ($cases as $name => [$output, $exit, $worker, $expectedExit, $expectedState]) {
            [$code, $stdout] = $this->health($output, $exit, $worker);
            $this->assertSame($expectedExit, $code, "{$name}: {$stdout}");
            $this->assertStringContainsString($expectedState, $stdout, $name);
        }
    }

    public function test_the_health_script_is_read_only(): void
    {
        $script = implode("\n", $this->lines($this->deploy('scripts/notifications-health.sh')));
        $this->assertStringContainsString('famboook:notifications-check', $script);
        $this->assertDoesNotMatchRegularExpression('/systemctl (restart|start|stop|enable)|queue:(restart|work|retry|flush)|migrate|db:seed|curl|mail /', $script);
    }
}
