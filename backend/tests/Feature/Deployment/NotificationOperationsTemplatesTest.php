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
 * invariant (timeout < retry_after < stale threshold), the cron line, the
 * env template's safe defaults, the deployment order and the documented list
 * of scheduled tasks. Static: it reads files only, starts nothing and touches
 * no database.
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

    public function test_the_cron_template_runs_the_scheduler_every_minute_as_www_data(): void
    {
        $cron = $this->deploy('cron/famboook-scheduler');
        $this->assertStringEndsWith("\n", $cron, 'cron.d ignores a last line without a newline');

        $jobs = array_values(array_filter($this->lines($cron), fn ($l) => ! preg_match('/^[A-Z_]+=/', $l)));
        $this->assertCount(1, $jobs);
        // /etc/cron.d format: five schedule fields, the user, the command.
        $this->assertMatchesRegularExpression('/^\* \* \* \* \* www-data cd \/var\/www\/famboook\/backend && \S+ artisan schedule:run --no-interaction >> \/dev\/null 2>&1$/', $jobs[0]);
        $this->assertNoSecrets($cron);
    }

    public function test_the_cron_template_documents_every_scheduled_task(): void
    {
        $cron = $this->deploy('cron/famboook-scheduler');
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            /** @var Event $event */
            $name = $event instanceof CallbackEvent
                ? ($event->description === NotificationHeartbeatJob::class ? 'NotificationHeartbeatJob' : (string) $event->description)
                : (preg_match('/(famboook:[a-z0-9:-]+)/', (string) $event->command, $m) ? $m[1] : (string) $event->command);
            $this->assertStringContainsString($name, $cron, "the cron template must document {$name}");
        }
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
            $this->assertDoesNotMatchRegularExpression('/systemctl (enable|start)|crontab|cron\.d|queue:work|schedule:(run|work)/', $commands, $name);
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
