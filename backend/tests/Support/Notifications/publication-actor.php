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
 *   claim-then-attempt <message_id>      T1, prints CLAIMED, waits for one more
 *                                        STDIN line (a stalled worker), then T2
 *                                        (and T3 if T2 refuses)
 *   reconcile <limit>                    NotificationPublicationReconciler::run;
 *                                        prints "OK <counters as JSON>"
 *   hold-recipient <recipient_id>        hold a notification_recipients row FOR UPDATE
 *   family-view <user_id> <uuid>         RecordNotificationViewAction as that head
 *   family-acknowledge <user_id> <uuid>  AcknowledgeNotificationAction as that head
 *                                        (both print "OK recorded" or "OK replayed")
 *   followups <limit>                    NotificationFollowupEngine::run with the follow-up
 *                                        gate ready (FB-NOTIFY-01E.3-F2); prints
 *                                        "OK <counters as JSON>"
 *   followups-pause <limit>              the same, pausing INSIDE the first follow-up
 *                                        transaction right after its first event INSERT
 *                                        (the recipient and follow-up rows held): prints
 *                                        PAUSED, then waits for one more STDIN line
 *   followup-contact[-pause] <user_id> <followup_uuid> <outcome>
 *   followup-close[-pause]   <user_id> <followup_uuid> <reason>
 *                                        RecordFollowupContactAction / CloseFollowupManuallyAction
 *                                        (FB-NOTIFY-01E.3-F3.1); -pause stops at the event INSERT,
 *                                        every lock held: PAUSED, then one more STDIN line.
 *                                        Prints "OK" or "DENIED <code>"
 *   revoke-scope <assignment_id> <admin_id>      RevokeCoordinatorScopeAction
 *   revoke-role <person_id> <admin_id>           RevokeCoordinatorRoleAction
 *   hold-revoke-role <person_id> <admin_id>      the same inside a held transaction
 *   assign-scope <person_id> <target> <admin_id>     AssignCoordinatorScopeAction; <target> is
 *                                        BRANCH:<id>, GROUP:<id>, CLAN:<id> or a bare branch id;
 *                                        prints "OK" or "REFUSED <code>"
 *   hold-assign <person_id> <target> <admin_id>      the same inside a held transaction
 *   mutate <name> <args…> / hold-mutate <name> <args…>
 *                                        an ownership-changing writer (F3.1d): update-family,
 *                                        update-branch, suspend-link, suspend-identity,
 *                                        record-death, staff-role — run, or held
 *
 * Synchronization: after READY each actor waits for one STDIN line
 * "<milliseconds>", waits that long and proceeds (holders then commit).
 *
 * Output: READY, then "OK <result>" or "FAIL <exception class> <SQLSTATE>".
 * Never a value.
 */

use App\Actions\AssignCoordinatorScopeAction;
use App\Actions\ManageClanStructureAction;
use App\Actions\ManageStaffUsersAction;
use App\Actions\Notifications\AcknowledgeNotificationAction;
use App\Actions\Notifications\CloseFollowupManuallyAction;
use App\Actions\Notifications\RecordFollowupContactAction;
use App\Actions\Notifications\RecordNotificationViewAction;
use App\Actions\RecordPersonDeathAction;
use App\Actions\RevokeCoordinatorRoleAction;
use App\Actions\RevokeCoordinatorScopeAction;
use App\Actions\SuspendUserPersonLinkAction;
use App\Actions\UpdateFamilyAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\CoordinatorRevokeReason;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkSuspensionReason;
use App\Exceptions\CoordinatorException;
use App\Exceptions\NotificationFollowupException;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\FamilyAuth\FamilySessions;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationHeartbeat;
use App\Support\Notifications\NotificationPublicationFailure;
use App\Support\Notifications\NotificationPublicationReconciler;
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

        case 'claim-then-attempt':
            [, , $messageId] = $argv;
            $publisher = app(NotificationPublisher::class);
            $out('READY');
            $wait();
            $token = $publisher->claim((int) $messageId);
            if (! is_string($token)) {
                $out('OK '.$token->value);
                break;
            }
            $out('CLAIMED');
            fgets(STDIN);
            try {
                $out('OK '.$publisher->attempt((int) $messageId, $token)->value);
            } catch (NotificationPublicationFailure $failure) {
                $out('OK '.$publisher->recordFailure((int) $messageId, $token, $failure->failure, $failure->cause)->value);
            }
            break;

        case 'reconcile':
            [, , $limit] = $argv;
            // A live worker's heartbeat, as in production (this process's cache).
            NotificationHeartbeat::record();
            $out('READY');
            $wait();
            $out('OK '.json_encode(app(NotificationPublicationReconciler::class)->run((int) $limit)->toArray()));
            break;

        case 'hold-recipient':
            [, , $recipientId] = $argv;
            DB::transaction(function () use ($recipientId, $out, $wait) {
                DB::select('SELECT id FROM notification_recipients WHERE id = ? FOR UPDATE', [(int) $recipientId]);
                $out('READY');
                $wait();
            });
            $out('OK');
            break;

        case 'family-view':
        case 'family-acknowledge':
            [, , $userId, $uuid] = $argv;
            $context = app(FamilyAccessResolver::class)->familyContext(User::findOrFail((int) $userId));
            $action = app($command === 'family-view' ? RecordNotificationViewAction::class : AcknowledgeNotificationAction::class);
            $out('READY');
            $wait();
            [, $recorded] = $action->handle($context, $uuid);
            $out('OK '.($recorded ? 'recorded' : 'replayed'));
            break;

        case 'followups':
        case 'followups-pause':
            [, , $limit] = $argv;
            // The follow-up gate ready: on, a cutoff long before every fixture, a live worker.
            config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2000-01-01T00:00:00Z']);
            NotificationHeartbeat::record();
            if ($command === 'followups-pause') {
                $paused = false;
                DB::listen(function ($query) use ($out, &$paused) {
                    if (! $paused && str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipient_events"')) {
                        $paused = true;
                        $out('PAUSED');
                        fgets(STDIN);
                    }
                });
            }
            $out('READY');
            $wait();
            $out('OK '.json_encode(app(NotificationFollowupEngine::class)->run((int) $limit)));
            break;

        case 'followup-contact':
        case 'followup-contact-pause':
        case 'followup-close':
        case 'followup-close-pause':
            [, , $userId, $uuid, $code] = $argv;
            if (str_ends_with($command, '-pause')) {
                $paused = false;
                DB::listen(function ($query) use ($out, &$paused) {
                    if (! $paused && str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipient_events"')) {
                        $paused = true;
                        $out('PAUSED');
                        fgets(STDIN);
                    }
                });
            }
            $actor = User::findOrFail((int) $userId);
            $out('READY');
            $wait();
            try {
                str_starts_with($command, 'followup-contact')
                    ? app(RecordFollowupContactAction::class)->handle($actor, $uuid, $code)
                    : app(CloseFollowupManuallyAction::class)->handle($actor, $uuid, $code);
                $out('OK');
            } catch (NotificationFollowupException $e) {
                $out('DENIED '.$e->reason);
            }
            break;

        case 'revoke-scope':
            [, , $assignmentId, $adminId] = $argv;
            $out('READY');
            $wait();
            app(RevokeCoordinatorScopeAction::class)->handle(User::findOrFail((int) $adminId),
                CoordinatorScopeAssignment::findOrFail((int) $assignmentId), CoordinatorRevokeReason::ADMINISTRATIVE);
            $out('OK');
            break;

        case 'revoke-role':
            [, , $personId, $adminId] = $argv;
            $out('READY');
            $wait();
            app(RevokeCoordinatorRoleAction::class)->handle(User::findOrFail((int) $adminId), Person::findOrFail((int) $personId), CoordinatorRevokeReason::ADMINISTRATIVE);
            $out('OK');
            break;

        case 'hold-revoke-role':
            [, , $personId, $adminId] = $argv;
            DB::transaction(function () use ($personId, $adminId, $out, $wait) {
                app(RevokeCoordinatorRoleAction::class)->handle(User::findOrFail((int) $adminId), Person::findOrFail((int) $personId), CoordinatorRevokeReason::ADMINISTRATIVE);
                $out('READY');
                $wait();
            });
            $out('OK');
            break;

        case 'assign-scope':
        case 'hold-assign':
            [, , $personId, $targetSpec, $adminId] = $argv;
            [$kind, $targetId] = str_contains($targetSpec, ':') ? explode(':', $targetSpec, 2) : ['BRANCH', $targetSpec];
            $target = match ($kind) {
                'CLAN' => Clan::findOrFail((int) $targetId),
                'GROUP' => BranchGroup::findOrFail((int) $targetId),
                default => Branch::findOrFail((int) $targetId),
            };
            $assign = fn () => app(AssignCoordinatorScopeAction::class)->handle(User::findOrFail((int) $adminId), Person::findOrFail((int) $personId), $target);
            if ($command === 'hold-assign') {
                DB::transaction(function () use ($assign, $out, $wait) {
                    $assign();
                    $out('READY');
                    $wait();
                });
                $out('OK');
                break;
            }
            $out('READY');
            $wait();
            try {
                $assign();
                $out('OK');
            } catch (CoordinatorException $e) {
                $out('REFUSED '.$e->reason);
            }
            break;

        case 'mutate':
        case 'hold-mutate':
            [, , $name] = $argv;
            $args = array_slice($argv, 3);
            $mutation = match ($name) {
                // UpdateFamilyAction (placement): <family_id> <branch_code|NONE>
                'update-family' => fn () => app(UpdateFamilyAction::class)->handle(Family::findOrFail((int) $args[0]),
                    ['branch_code' => $args[1] === 'NONE' ? null : $args[1]], null),
                // ManageClanStructureAction::updateBranch: <branch_id> inactive | group:<group_id>
                'update-branch' => fn () => app(ManageClanStructureAction::class)->updateBranch(Branch::findOrFail((int) $args[0]),
                    $args[1] === 'inactive' ? ['is_active' => false] : ['branch_group' => BranchGroup::findOrFail((int) substr($args[1], 6))]),
                // SuspendUserPersonLinkAction: <link_id> <admin_id>
                'suspend-link' => fn () => app(SuspendUserPersonLinkAction::class)->handle(User::findOrFail((int) $args[1]),
                    UserPersonLink::findOrFail((int) $args[0]), UserPersonLinkSuspensionReason::ADMINISTRATIVE),
                // The identity-suspension writer's own lock order (FamilyAuthIdentities::
                // syncAfterNationalIdCorrection: person → link → identity → user), which
                // no production input reaches today: <person_id>
                'suspend-identity' => function () use ($args) {
                    Person::query()->whereKey((int) $args[0])->lockForUpdate()->firstOrFail();
                    $link = UserPersonLink::query()->where('person_id', (int) $args[0])->current()->lockForUpdate()->firstOrFail();
                    $identity = app(FamilyAuthIdentities::class)->current($link->user, lock: true);
                    $identity->forceFill(['status' => AuthIdentityStatus::SUSPENDED])->save();
                    FamilySessions::revoke($link->user);
                },
                // RecordPersonDeathAction: <person_id>
                'record-death' => fn () => app(RecordPersonDeathAction::class)->handle(Person::findOrFail((int) $args[0]), null,
                    LifeStatusVerificationMethod::IN_PERSON, null),
                // ManageStaffUsersAction::update (role only): <user_id> <role> <admin_id>
                'staff-role' => fn () => app(ManageStaffUsersAction::class)->update(User::findOrFail((int) $args[2]),
                    User::findOrFail((int) $args[0]), ['role' => $args[1]]),
            };
            if ($command === 'hold-mutate') {
                DB::transaction(function () use ($mutation, $out, $wait) {
                    $mutation();
                    $out('READY');
                    $wait();
                });
                $out('OK');
                break;
            }
            $out('READY');
            $wait();
            DB::transaction(fn () => $mutation());
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
