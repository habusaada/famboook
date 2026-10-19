<?php

/*
 * TEST-ONLY helper for the PostgreSQL notification publication concurrency
 * tests (FB-NOTIFY-01B.2b.5c): runs ONE worker step or lock holder in its OWN
 * PHP process and database session, so transactions genuinely interleave.
 * Started by the test with proc_open; it inherits the test's database
 * environment and sets the notification configuration the test uses.
 *
 * It refuses to run unless the connection is PostgreSQL and the database
 * name ends in _test (never a development or Production database).
 *
 * Commands (argv):
 *   hold <message_id>                    hold the message row FOR UPDATE
 *   publish <message_id>                 NotificationPublisher::publish
 *   publish-pause <message_id>           the same, pausing INSIDE T2 right after
 *                                        the recipient INSERT: prints INSERTED,
 *                                        then waits for one more STDIN line
 *   hold-revoke <assignment_id> <actor>  revoke the assignment inside a held
 *                                        transaction (committed after the wait)
 *
 * Synchronization: after READY each actor waits for one STDIN line
 * "<milliseconds>", waits that long and proceeds (holders then commit).
 *
 * Output: READY, then "OK <result>" or "FAIL <exception class> <SQLSTATE>".
 * Never a value.
 */

use App\Actions\RevokeCoordinatorScopeAction;
use App\Enums\CoordinatorRevokeReason;
use App\Models\CoordinatorScopeAssignment;
use App\Models\User;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = static function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
    fflush(STDOUT);
};

if (DB::getDriverName() !== 'pgsql' || ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
    $out('FAIL refused not-a-test-database');
    exit(2);
}

// The configuration of NotificationActionFixtures (the worker needs no heartbeat).
config([
    'notifications.mode' => 'ON', 'notifications.pilot_sender_user_ids' => '', 'notifications.system_discovery_enabled' => false,
    'notifications.followups_enabled' => false, 'notifications.system_cutoff' => null, 'notifications.queue' => 'notifications',
    'notifications.heartbeat_max_age_seconds' => 300, 'notifications.automatic_publish_attempts' => 3,
    'notifications.manual_publish_retries' => 1, 'notifications.legacy_max_publish_attempts' => null,
    'notifications.stale_publishing_seconds' => 600, 'queue.default' => 'database',
    'family_auth.fingerprint' => ['key' => 'test-only-family-auth-key-0123456789-abcdef', 'key_version' => 1, 'previous_key' => null, 'previous_key_version' => null],
]);

$wait = static function (): void {
    $line = fgets(STDIN);
    usleep(max(0, (int) trim((string) $line)) * 1000);
};

[, $command] = $argv + [null, null];

try {
    switch ($command) {
        case 'hold':
            [, , $messageId] = $argv;
            DB::transaction(function () use ($messageId, $out, $wait) {
                DB::select('SELECT id FROM notification_messages WHERE id = ? FOR UPDATE', [(int) $messageId]);
                $out('READY');
                $wait();
            });
            $out('OK');
            break;

        case 'publish':
        case 'publish-pause':
            [, , $messageId] = $argv;
            if ($command === 'publish-pause') {
                DB::listen(function ($query) use ($out) {
                    if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipients"')) {
                        $out('INSERTED');
                        fgets(STDIN);
                    }
                });
            }
            $out('READY');
            $wait();
            $out('OK '.app(NotificationPublisher::class)->publish((int) $messageId)->value);
            break;

        case 'hold-revoke':
            [, , $assignmentId, $actorId] = $argv;
            DB::transaction(function () use ($assignmentId, $actorId, $out, $wait) {
                $assignment = CoordinatorScopeAssignment::query()->whereKey((int) $assignmentId)->lockForUpdate()->firstOrFail();
                RevokeCoordinatorScopeAction::revoke($assignment, User::findOrFail((int) $actorId), CoordinatorRevokeReason::ADMINISTRATIVE);
                $out('READY');
                $wait();
            });
            $out('OK');
            break;

        default:
            $out('FAIL unknown-command');
            exit(2);
    }
} catch (QueryException $e) {
    $out('FAIL '.$e::class.' '.$e->getCode());
    exit(1);
} catch (Throwable $e) {
    $out('FAIL '.$e::class);
    exit(1);
}
