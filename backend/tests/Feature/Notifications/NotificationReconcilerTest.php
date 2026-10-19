<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationKind;
use App\Enums\NotificationPublicationResult as Result;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\Notifications\NotificationPublicationReconciler;
use App\Support\Notifications\NotificationPublisher;
use App\Support\Notifications\NotificationReconciliationReport;
use App\Support\Notifications\NotificationRetryPolicy;
use App\Support\Notifications\QueueNotificationPublicationDispatcher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.5d: NotificationPublicationReconciler — stale PUBLISHING
 * recovery, automatic retries through NotificationRetryPolicy, and the
 * throttled redispatch of orphaned QUEUED messages; gates and readiness;
 * idempotency; the batch limit; safe counters. Driver-agnostic (also run on
 * PostgreSQL); session races are NotificationReconciliationConcurrencyTest.
 * The dispatcher records: nothing is ever published here. Synthetic data
 * only.
 */
class NotificationReconcilerTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->staff = $this->staffSender();
        $this->at('2026-10-19 10:00:00');
    }

    /** Moves the clock and keeps the worker heartbeat fresh (as a live worker would). */
    private function at(string $time): void
    {
        $this->travelTo($time);
        $this->freshHeartbeat();
    }

    private function later(int $seconds): void
    {
        $this->travel($seconds)->seconds();
        $this->freshHeartbeat();
    }

    private function queued(?User $sender = null, array $input = []): NotificationMessage
    {
        $sender ??= $this->staff;
        $message = app(RequestPublicationAction::class)->handle($sender,
            $this->draft($sender, ['type' => 'STAFF_FAMILIES', 'family_ids' => [Family::factory()->create()->id]], $input))->message->fresh();
        $this->dispatcher->dispatched = [];

        return $message;
    }

    private function failed(int $attempts, NotificationFailureCode|string $code = NotificationFailureCode::PUBLICATION_ERROR, array $input = []): NotificationMessage
    {
        return $this->failPublication($this->queued(input: $input), $code, $attempts);
    }

    private function reconcile(int $limit = 100): NotificationReconciliationReport
    {
        return app(NotificationPublicationReconciler::class)->run($limit);
    }

    /** @return list<array{0: string, 1: string|null, 2: int|null}> the message's REQUEUED events: actor side, reason, user */
    private function requeues(NotificationMessage $message): array
    {
        return NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->orderBy('id')->get()
            ->map(fn ($e) => [$e->actor_side->value, $e->reason_code, $e->actor_user_id])->all();
    }

    // ------------------------------------------------------ orphaned QUEUED

    public function test_an_orphaned_queued_message_is_handed_off_again_after_180_seconds_without_a_state_change(): void
    {
        $message = $this->queued();

        $this->later(179);
        $this->assertSame(0, $this->reconcile()->orphanedRedispatched);
        $this->assertSame([], $this->dispatcher->ids());

        $this->later(1);
        $report = $this->reconcile();
        $this->assertSame(1, $report->orphanedRedispatched);
        $this->assertSame([(int) $message->id], $this->dispatcher->ids());
        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::QUEUED, 0, '2026-10-19 10:00:00'],
            [$stored->status, $stored->publish_attempts, $stored->queued_at->format('Y-m-d H:i:s')]);
        $this->assertSame('2026-10-19 10:03:00', $stored->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(['QUEUED'], NotificationRecipientEvent::query()->where('message_id', $message->id)->pluck('event_type')->map->value->all());
    }

    public function test_a_redispatch_is_throttled_to_once_per_300_seconds(): void
    {
        $message = $this->queued();
        $this->later(180);
        $this->reconcile();

        $this->later(299);
        $this->assertSame(0, $this->reconcile()->orphanedRedispatched);
        $this->later(1);
        $this->assertSame(1, $this->reconcile()->orphanedRedispatched);
        $this->assertSame([(int) $message->id, (int) $message->id], $this->dispatcher->ids());
    }

    public function test_a_repeated_run_is_idempotent(): void
    {
        $orphan = $this->queued();
        $failed = $this->failed(1);
        $this->later(200);

        $first = $this->reconcile();
        $second = $this->reconcile();

        $this->assertSame([1, 1], [$first->orphanedRedispatched, $first->automaticRequeued]);
        $this->assertSame([0, 0, 0], [$second->orphanedRedispatched, $second->automaticRequeued, $second->staleRecovered]);
        $this->assertCount(1, $this->requeues($failed));
        $this->assertSame([(int) $failed->id, (int) $orphan->id], $this->dispatcher->ids());
    }

    public function test_cancelled_and_published_messages_are_never_handed_off(): void
    {
        $cancelled = $this->queued();
        app(CancelPublicationAction::class)->handle($this->staff, $cancelled);
        $published = $this->queued();
        app(NotificationPublisher::class)->publish($published->id);
        $this->later(600);

        $this->assertSame(0, $this->reconcile()->orphanedRedispatched);
        $this->assertSame([], $this->dispatcher->ids());
    }

    // ----------------------------------------------------------------- gates

    public function test_off_or_unready_infrastructure_pauses_every_action_that_could_publish(): void
    {
        $this->queued();
        $this->failed(1);
        $this->later(200);

        config(['notifications.mode' => 'OFF']);
        $report = $this->reconcile();
        $this->assertSame([2, 0, 0], [$report->paused, $report->orphanedRedispatched, $report->automaticRequeued]);

        config(['notifications.mode' => 'ON', 'queue.default' => 'sync']);
        $this->assertSame(2, $this->reconcile()->paused);
        config(['queue.default' => 'database']);
        $this->travel(301)->seconds(); // the heartbeat is now stale
        $this->assertSame(2, $this->reconcile()->paused);

        $this->assertSame([], $this->dispatcher->ids());
    }

    public function test_pilot_hands_off_only_allow_listed_senders(): void
    {
        $other = $this->staffSender();
        $allowed = $this->queued();
        $excluded = $this->queued($other);
        $this->later(200);
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => (string) $this->staff->id]);

        $this->assertSame(1, $this->reconcile()->orphanedRedispatched);
        $this->assertSame([(int) $allowed->id], $this->dispatcher->ids());
        $this->assertNotSame((int) $excluded->id, $this->dispatcher->ids()[0]);
    }

    public function test_system_messages_need_on_their_gate_and_a_valid_cutoff(): void
    {
        $request = ChangeRequest::factory()->create();
        $event = WorkflowEvent::create(['workflowable_type' => 'change_request', 'workflowable_id' => $request->id, 'from_status' => null,
            'to_status' => 'APPLIED', 'event_type' => 'APPLIED', 'actor_user_id' => null, 'actor_side' => 'SYSTEM']);
        $message = new NotificationMessage;
        $message->forceFill(['kind' => NotificationKind::SYSTEM, 'sender_side' => NotificationSenderSide::SYSTEM, 'title' => 'تم تطبيق طلبك',
            'body' => 'تم تطبيق طلب التحديث.', 'origin_workflow_event_id' => $event->id, 'status' => NotificationStatus::QUEUED, 'queued_at' => now()])->save();
        $this->later(200);

        $this->assertSame(0, $this->reconcile()->orphanedRedispatched, 'gate closed');
        config(['notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-13-01T00:00:00Z']);
        $this->assertSame(0, $this->reconcile()->orphanedRedispatched, 'cutoff invalid');
        config(['notifications.system_cutoff' => '2026-01-01T00:00:00Z', 'notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => (string) $this->staff->id]);
        $this->assertSame(0, $this->reconcile()->orphanedRedispatched, 'PILOT');
        config(['notifications.mode' => 'ON']);
        $this->assertSame(1, $this->reconcile()->orphanedRedispatched);
        $this->assertSame([(int) $message->id], $this->dispatcher->ids());
    }

    // ---------------------------------------------------------------- stale

    public function test_a_stale_claim_is_recovered_and_its_late_worker_can_write_nothing(): void
    {
        $message = $this->queued();
        $publisher = app(NotificationPublisher::class);
        $token = $publisher->claim($message->id);

        $this->later(599);
        $this->assertSame(0, $this->reconcile()->staleRecovered);
        $this->later(1);
        $this->assertSame(1, $this->reconcile()->staleRecovered);

        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::PUBLISH_FAILED, 'STALE_PUBLISHING', 1, null],
            [$stored->status, $stored->publish_failure_code, $stored->publish_attempts, $stored->publishing_token]);
        $this->assertNotNull($stored->publishing_started_at);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->where('event_type', 'PUBLISH_FAILED')->sole();
        $metadata = $event->metadata;
        ksort($metadata);
        $this->assertSame(['SYSTEM', 'STALE_PUBLISHING', ['attempt' => 1, 'cause' => 'STALE_RECOVERED']], [$event->actor_side->value, $event->reason_code, $metadata]);

        // The late worker: neither T2 nor T3 may write.
        $this->assertSame(Result::SUPERSEDED, $publisher->attempt($message->id, $token));
        $this->assertSame(Result::SUPERSEDED, $publisher->recordFailure($message->id, $token, NotificationFailureCode::PUBLICATION_ERROR));
        $this->assertSame('STALE_PUBLISHING', $message->fresh()->publish_failure_code);
        $this->assertSame(0, DB::table('notification_recipients')->where('message_id', $message->id)->count());
    }

    public function test_stale_recovery_runs_even_when_the_feature_is_off(): void
    {
        $message = $this->queued();
        app(NotificationPublisher::class)->claim($message->id);
        $this->later(600);
        config(['notifications.mode' => 'OFF']);

        $this->assertSame(1, $this->reconcile()->staleRecovered);
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);
        $this->assertSame([], $this->dispatcher->ids());
    }

    // ------------------------------------------------------ automatic retry

    public function test_automatic_retries_respect_both_backoff_boundaries_and_are_attributed_to_system(): void
    {
        foreach ([1 => 60, 2 => 300] as $attempts => $backoff) {
            $this->at('2026-10-19 10:00:00');
            $message = $this->failed($attempts);

            $this->later($backoff - 1);
            $this->assertSame(0, $this->reconcile()->automaticRequeued, "attempt {$attempts}, 1 s early");
            $this->later(1);
            $this->assertSame(1, $this->reconcile()->automaticRequeued, "attempt {$attempts}, at the boundary");

            $stored = $message->fresh();
            $this->assertSame([NotificationStatus::QUEUED, $attempts], [$stored->status, $stored->publish_attempts]);
            $this->assertSame([['SYSTEM', 'PUBLICATION_ERROR', null]], $this->requeues($message));
            $this->assertSame(0, app(NotificationRetryPolicy::class)->manualRetriesUsed($message), 'the manual allowance is untouched');
            $this->assertContains((int) $message->id, $this->dispatcher->ids());
            $this->dispatcher->dispatched = [];
        }
    }

    public function test_exhausted_manual_only_permanent_unknown_and_expiring_failures_are_never_requeued(): void
    {
        $messages = [
            $this->failed(3),
            $this->failed(1, NotificationFailureCode::AUDIENCE_EMPTY),
            $this->failed(1, NotificationFailureCode::SENDER_NOT_AUTHORIZED),
            $this->failed(1, NotificationFailureCode::EXPIRED_BEFORE_PUBLICATION),
            $this->failed(1, 'NOT_A_CODE'),
            $this->failed(1, input: ['expires_at' => now()->addMinutes(61)->toIso8601String()]),
        ];
        $this->later(3600);

        $report = $this->reconcile();

        $this->assertSame(0, $report->automaticRequeued);
        foreach ($messages as $message) {
            $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);
            $this->assertSame([], $this->requeues($message));
        }
        $this->assertSame([], $this->dispatcher->ids());
    }

    public function test_a_requeue_and_its_audit_event_roll_back_together(): void
    {
        $message = $this->failed(1);
        $this->later(60);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipient_events"')) {
                throw new RuntimeException('audit write failed');
            }
        });

        $report = $this->reconcile();

        $this->assertSame([1, 0], [$report->errors, $report->automaticRequeued]);
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);
        $this->assertSame([], $this->requeues($message));
        $this->assertSame([], $this->dispatcher->ids());
    }

    // ------------------------------------------------- dispatch and limits

    public function test_a_failed_push_leaves_the_message_queued_and_recoverable(): void
    {
        Log::spy();
        $message = $this->queued();
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('queue down: 0591234567'));
        $this->app->instance(NotificationPublicationDispatcher::class, new QueueNotificationPublicationDispatcher($bus));

        $this->later(180);
        $this->assertSame(1, $this->reconcile()->orphanedRedispatched);
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
        $this->later(299);
        $this->assertSame(0, $this->reconcile()->orphanedRedispatched);
        $this->later(1);
        $this->assertSame(1, $this->reconcile()->orphanedRedispatched);

        Log::shouldHaveReceived('warning')->withArgs(fn ($text, $context) => $context === [
            'message' => $message->uuid, 'code' => 'DISPATCH_FAILED', 'exception' => RuntimeException::class,
        ])->twice();
    }

    public function test_the_batch_limit_bounds_one_run(): void
    {
        $messages = collect(range(1, 5))->map(fn () => $this->queued());
        $this->later(200);

        $this->assertSame(2, $this->reconcile(limit: 2)->orphanedRedispatched);
        $this->assertSame(2, $this->reconcile(limit: 2)->orphanedRedispatched);
        $this->assertSame(1, $this->reconcile(limit: 2)->orphanedRedispatched);
        $this->assertSame($messages->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(), collect($this->dispatcher->ids())->sort()->values()->all());
    }

    public function test_a_redispatched_message_and_its_original_job_publish_once(): void
    {
        $message = $this->queued();
        $this->later(180);
        $this->reconcile();
        $publisher = app(NotificationPublisher::class);

        $this->assertSame(Result::PUBLISHED, $publisher->publish($message->id));
        $this->assertSame(Result::NOT_CLAIMED, $publisher->publish($message->id));
        $this->assertSame(1, DB::table('notification_recipients')->where('message_id', $message->id)->count());
    }

    // --------------------------------------------------- command and schedule

    public function test_the_command_reports_safe_counters_only(): void
    {
        Log::spy();
        $this->queued(input: ['title' => 'عنوان سري تجريبي']);
        $this->later(200);

        $this->artisan('famboook:notifications-reconcile')
            ->expectsOutput('scanned: 1')
            ->expectsOutput('orphaned_redispatched: 1')
            ->expectsOutput('stale_recovered: 0')
            ->expectsOutput('automatic_requeued: 0')
            ->expectsOutput('paused: 0')
            ->expectsOutput('skipped: 0')
            ->expectsOutput('errors: 0')
            ->doesntExpectOutputToContain('سري')
            ->assertExitCode(0);
        Log::shouldHaveReceived('info')->withArgs(fn ($text, $context = []) => $context === [
            'scanned' => 1, 'orphaned_redispatched' => 1, 'stale_recovered' => 0, 'automatic_requeued' => 0, 'paused' => 0, 'skipped' => 0, 'errors' => 0,
        ])->once();

        foreach (['0', '1001', 'many'] as $limit) {
            $this->artisan('famboook:notifications-reconcile', ['--limit' => $limit])->assertExitCode(2);
        }
    }

    public function test_it_is_scheduled_every_minute_without_overlapping_on_one_server(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'famboook:notifications-reconcile')));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertSame(10, $events[0]->expiresAt);
    }
}
