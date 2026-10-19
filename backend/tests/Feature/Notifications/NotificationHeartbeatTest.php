<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationHeartbeatState;
use App\Enums\NotificationReadinessReason as Reason;
use App\Jobs\NotificationHeartbeatJob;
use App\Support\Notifications\NotificationHeartbeat;
use App\Support\Notifications\NotificationReadiness;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationReadinessFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b: the scheduler dispatches NotificationHeartbeatJob every
 * minute onto the notifications queue; running it records the server time
 * and nothing else — no family data, no publication, no notification table.
 * The queue is faked or the job run directly: no worker is started.
 */
class NotificationHeartbeatTest extends TestCase
{
    use NotificationReadinessFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationDefaults();
    }

    private function scheduledHeartbeat(): Event
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => $event->description === NotificationHeartbeatJob::class,
        ));
        $this->assertCount(1, $events);

        return $events[0];
    }

    public function test_the_job_is_queued_on_the_dedicated_notifications_queue(): void
    {
        $job = new NotificationHeartbeatJob;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame('notifications', $job->queue);
        $this->assertSame(1, $job->tries);
    }

    public function test_the_scheduler_dispatches_it_every_minute_onto_the_notifications_queue(): void
    {
        Queue::fake();
        $event = $this->scheduledHeartbeat();

        $this->assertSame('* * * * *', $event->expression);
        $event->run($this->app);

        Queue::assertPushedOn('notifications', NotificationHeartbeatJob::class);
        Queue::assertCount(1);
        // Dispatching is not running: no heartbeat until a worker runs it.
        $this->assertSame(NotificationHeartbeatState::ABSENT, NotificationHeartbeat::state());
    }

    public function test_running_it_records_the_server_time_only(): void
    {
        $this->travelTo('2026-10-19 10:00:00');

        (new NotificationHeartbeatJob)->handle();

        $this->assertSame(now()->getTimestamp(), Cache::get(NotificationHeartbeat::CACHE_KEY));
        $this->assertSame(0, NotificationHeartbeat::ageSeconds());
        $this->assertSame(NotificationHeartbeatState::FRESH, NotificationHeartbeat::state());
    }

    public function test_duplicate_execution_is_harmless_and_the_latest_run_wins(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $job = new NotificationHeartbeatJob;
        $job->handle();
        $job->handle();
        $this->travel(400)->seconds();
        $this->assertSame(NotificationHeartbeatState::STALE, NotificationHeartbeat::state());

        (new NotificationHeartbeatJob)->handle();

        $this->assertSame(now()->getTimestamp(), Cache::get(NotificationHeartbeat::CACHE_KEY));
        $this->assertSame(NotificationHeartbeatState::FRESH, NotificationHeartbeat::state());
    }

    public function test_an_invalid_queue_name_keeps_the_default_queue(): void
    {
        config(['notifications.queue' => 'Not Valid!']);

        $this->assertSame('notifications', (new NotificationHeartbeatJob)->queue);
    }

    public function test_it_touches_no_database_and_carries_no_payload(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        (new NotificationHeartbeatJob)->handle();

        $this->assertSame(0, $queries);
        $this->assertSame([], array_diff_key(get_object_vars(new NotificationHeartbeatJob), get_class_vars(NotificationHeartbeatJob::class)));
    }

    public function test_a_heartbeat_written_through_the_sync_queue_still_never_makes_sync_ready(): void
    {
        // The test environment's queue is sync: the dispatch runs in-process.
        config(['queue.default' => 'sync', 'notifications.mode' => 'ON']);
        NotificationHeartbeatJob::dispatch();

        $this->assertSame(NotificationHeartbeatState::FRESH, NotificationHeartbeat::state());
        $this->assertSame([Reason::QUEUE_NOT_ASYNC], NotificationReadiness::forPublication());
        foreach (['notification_messages', 'notification_recipients', 'notification_followups', 'notification_recipient_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
