<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NotificationReadinessFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b: famboook:notifications-check reports the mode, gates,
 * queue driver, heartbeat, readiness codes and counts only — never an id,
 * title, body or recipient — writes nothing and survives a database without
 * the notification tables. Synthetic data only.
 */
class NotificationsCheckCommandTest extends TestCase
{
    use NotificationReadinessFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationDefaults();
    }

    private function message(NotificationStatus $status, ?\DateTimeInterface $startedAt = null): NotificationMessage
    {
        $message = NotificationMessage::create([
            'kind' => NotificationKind::ANNOUNCEMENT, 'sender_side' => NotificationSenderSide::STAFF,
            'title' => 'عنوان سري تجريبي', 'body' => 'نص سري تجريبي.', 'sender_user_id' => User::factory()->create()->id,
        ]);
        $message->forceFill(['status' => $status, 'queued_at' => now()->subHour()] + ($status === NotificationStatus::PUBLISHING
            ? ['publishing_started_at' => $startedAt ?? now(), 'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1]
            : []))->save();

        return $message;
    }

    public function test_the_release_default_is_off_with_no_warnings(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Notification mode (effective): OFF')
            ->expectsOutputToContain('Configuration: VALID')
            ->expectsOutputToContain('Pilot sender allow-list: empty')
            ->expectsOutputToContain('SYSTEM discovery gate: OFF')
            ->expectsOutputToContain('Follow-ups gate: OFF')
            ->expectsOutputToContain('SYSTEM cutoff: NOT SET')
            ->expectsOutputToContain('Queue connection driver: sync (NOT asynchronous)')
            ->expectsOutputToContain('Notification queue: notifications')
            ->expectsOutputToContain('Worker heartbeat: ABSENT')
            ->expectsOutputToContain('Scheduler/worker: NOT READY')
            ->expectsOutputToContain('Publication readiness: NOT READY (MODE_OFF, QUEUE_NOT_ASYNC, HEARTBEAT_ABSENT)')
            ->expectsOutputToContain('SYSTEM discovery readiness: NOT READY (MODE_OFF, QUEUE_NOT_ASYNC, HEARTBEAT_ABSENT, SYSTEM_DISCOVERY_DISABLED, SYSTEM_CUTOFF_MISSING)')
            ->expectsOutputToContain('Follow-up readiness: NOT READY (MODE_OFF, QUEUE_NOT_ASYNC, HEARTBEAT_ABSENT, FOLLOWUPS_DISABLED)')
            ->expectsOutputToContain('Notification tables: present')
            ->expectsOutputToContain('Messages QUEUED: 0')
            ->expectsOutputToContain('Messages PUBLISHING: 0')
            ->expectsOutputToContain('Stale PUBLISHING (> 600 s): 0')
            ->expectsOutputToContain('Failed jobs (notification queue): 0')
            ->expectsOutputToContain('Notifications: no warnings.')
            ->assertExitCode(0);
    }

    public function test_a_fully_ready_system_reports_ready_everywhere(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->infrastructureReady();
        $this->travel(42)->seconds();
        config([
            'notifications.mode' => 'ON', 'notifications.system_discovery_enabled' => true,
            'notifications.followups_enabled' => true, 'notifications.system_cutoff' => '2026-11-01T00:00:00Z',
        ]);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Queue connection driver: database (asynchronous)')
            ->expectsOutputToContain('Worker heartbeat: FRESH (age 42 s, max 300 s)')
            ->expectsOutputToContain('Scheduler/worker: READY')
            ->expectsOutputToContain('SYSTEM cutoff: VALID')
            ->expectsOutputToContain('Publication readiness: READY')
            ->expectsOutputToContain('SYSTEM discovery readiness: READY')
            ->expectsOutputToContain('Follow-up readiness: READY')
            ->expectsOutputToContain('Notifications: no warnings.')
            ->assertExitCode(0);
    }

    public function test_an_enabled_mode_that_is_not_ready_warns(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->infrastructureReady();
        $this->travel(301)->seconds();
        config(['notifications.mode' => 'ON', 'notifications.system_discovery_enabled' => true]);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Worker heartbeat: STALE (age 301 s, max 300 s)')
            ->expectsOutputToContain('WARN: Notifications are enabled but publication is not ready: HEARTBEAT_STALE.')
            ->expectsOutputToContain('WARN: SYSTEM discovery is enabled but not ready: HEARTBEAT_STALE, SYSTEM_CUTOFF_MISSING.')
            ->assertExitCode(1);
    }

    public function test_system_discovery_enabled_in_pilot_warns_that_it_requires_on(): void
    {
        $this->infrastructureReady();
        config([
            'notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '987654',
            'notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-11-01T00:00:00Z',
        ]);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Publication readiness: READY')
            ->expectsOutputToContain('SYSTEM discovery readiness: NOT READY (SYSTEM_DISCOVERY_REQUIRES_ON)')
            ->expectsOutputToContain('WARN: SYSTEM discovery is enabled but not ready: SYSTEM_DISCOVERY_REQUIRES_ON.')
            ->assertExitCode(1);
    }

    public function test_invalid_configuration_and_cutoff_warn_without_printing_values(): void
    {
        config([
            'notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '987654,x',
            'notifications.queue' => 'Bad Queue', 'notifications.system_cutoff' => '2026-02-30T00:00:00Z',
        ]);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Notification mode (effective): PILOT')
            ->expectsOutputToContain('Configuration: INVALID (pilot_sender_user_ids, queue)')
            ->expectsOutputToContain('Pilot sender allow-list: INVALID (no sender may publish in PILOT)')
            ->expectsOutputToContain('Notification queue: INVALID')
            ->expectsOutputToContain('SYSTEM cutoff: INVALID')
            ->expectsOutputToContain('WARN: Invalid notification configuration (pilot_sender_user_ids, queue)')
            ->expectsOutputToContain('WARN: NOTIFICATIONS_SYSTEM_CUTOFF is invalid')
            ->expectsOutputToContain('WARN: PILOT mode with no usable allow-listed sender: nobody can publish.')
            ->doesntExpectOutputToContain('987654')
            ->doesntExpectOutputToContain('Bad Queue')
            ->doesntExpectOutputToContain('2026-02-30')
            ->assertExitCode(1);
    }

    public function test_the_retry_policy_is_reported_and_the_replaced_key_is_flagged(): void
    {
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Retry policy: 3 automatic attempt(s), 1 manual retry(ies), at most 4 claim(s)')
            ->expectsOutputToContain('Legacy NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS set: NO')
            ->assertExitCode(0);

        // The replaced total-attempts key changes nothing, but must be removed.
        config(['notifications.legacy_max_publish_attempts' => '5']);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Retry policy: 3 automatic attempt(s), 1 manual retry(ies), at most 4 claim(s)')
            ->expectsOutputToContain('Legacy NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS set: YES')
            ->expectsOutputToContain('WARN: Remove NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS')
            ->assertExitCode(1);
    }

    public function test_an_invalid_retry_policy_is_reported_as_invalid_configuration(): void
    {
        config(['notifications.manual_publish_retries' => 3]);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Configuration: INVALID (manual_publish_retries)')
            ->expectsOutputToContain('Retry policy: INVALID (no retry)')
            ->assertExitCode(1);
    }

    public function test_a_pilot_allow_list_is_reported_as_a_count_only(): void
    {
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '987654,876543']);

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Pilot sender allow-list: 2 id(s)')
            ->doesntExpectOutputToContain('987654')
            ->doesntExpectOutputToContain('876543');
    }

    public function test_it_counts_queued_publishing_stale_and_failed_jobs_without_content(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->message(NotificationStatus::QUEUED);
        $this->message(NotificationStatus::QUEUED);
        $this->message(NotificationStatus::PUBLISHING, now()->subSeconds(60));
        $this->message(NotificationStatus::PUBLISHING, now()->subSeconds(601));
        foreach (['notifications', 'notifications', 'default'] as $queue) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $queue,
                'payload' => '{}', 'exception' => 'synthetic', 'failed_at' => now(),
            ]);
        }

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Messages QUEUED: 2')
            ->expectsOutputToContain('Messages PUBLISHING: 2')
            ->expectsOutputToContain('Stale PUBLISHING (> 600 s): 1')
            ->expectsOutputToContain('Failed jobs (notification queue): 2')
            ->expectsOutputToContain('WARN: 1 PUBLISHING message(s) are stale candidates for recovery.')
            ->doesntExpectOutputToContain('سري')
            ->doesntExpectOutputToContain('NTF-')
            ->assertExitCode(1);
    }

    public function test_it_is_safe_without_the_notification_and_failed_job_tables(): void
    {
        foreach (['notification_recipient_events', 'notification_followups', 'notification_recipients', 'notification_messages', 'failed_jobs'] as $table) {
            Schema::drop($table);
        }

        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Notification tables: ABSENT')
            ->expectsOutputToContain('Messages QUEUED: unavailable')
            ->expectsOutputToContain('Messages PUBLISHING: unavailable')
            ->expectsOutputToContain('Stale PUBLISHING: unavailable')
            ->expectsOutputToContain('Failed jobs (notification queue): unavailable')
            ->expectsOutputToContain('Notifications: no warnings.')
            ->assertExitCode(0);

        config(['notifications.mode' => 'ON']);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('WARN: Notifications are enabled but the notification tables do not exist in this database.')
            ->assertExitCode(1);
    }

    public function test_the_check_dispatches_nothing_and_writes_nothing(): void
    {
        Queue::fake();
        $this->message(NotificationStatus::QUEUED);
        $before = DB::table('notification_messages')->get()->toJson();

        $writes = $this->writesDuring(fn () => Artisan::call('famboook:notifications-check'));

        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table('notification_messages')->get()->toJson());
    }
}
