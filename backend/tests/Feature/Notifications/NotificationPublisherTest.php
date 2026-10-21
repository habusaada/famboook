<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationKind;
use App\Enums\NotificationPublicationResult as Result;
use App\Enums\NotificationReadinessReason;
use App\Enums\NotificationRetryDecision;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Jobs\PublishNotificationJob;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationPublicationFailure;
use App\Support\Notifications\NotificationPublisher;
use App\Support\Notifications\NotificationReadiness;
use App\Support\Notifications\NotificationRetryPolicy;
use App\Support\Notifications\QueueNotificationPublicationDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.5c: the publication worker — PublishNotificationJob,
 * QueueNotificationPublicationDispatcher and NotificationPublisher's T1 claim,
 * T2 atomic publication and T3 failure. Driver-agnostic: on PostgreSQL
 * (phpunit.pgsql.xml) the same tests exercise the real INSERT … SELECT, the
 * CHECK constraints and the composite foreign keys. Races between sessions
 * are NotificationPublicationConcurrencyTest. Synthetic data only.
 */
class NotificationPublisherTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private NotificationPublisher $publisher;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->publisher = app(NotificationPublisher::class);
        $this->staff = $this->staffSender();
    }

    /** A QUEUED Staff announcement to $families (the request action, the dispatcher recorded). */
    /** @param  iterable<Family>  $families */
    private function queued(iterable $families, ?User $sender = null, array $input = []): NotificationMessage
    {
        $sender ??= $this->staff;
        $draft = $this->draft($sender, ['type' => 'STAFF_FAMILIES', 'family_ids' => collect($families)->pluck('id')->all()], $input);

        return app(RequestPublicationAction::class)->handle($sender, $draft)->message->fresh();
    }

    /** @return list<string> the message's event types in order */
    private function events(NotificationMessage $message): array
    {
        return NotificationRecipientEvent::query()->where('message_id', $message->id)->orderBy('id')->pluck('event_type')
            ->map(fn ($type) => $type->value)->all();
    }

    private function recipients(NotificationMessage $message): int
    {
        return DB::table('notification_recipients')->where('message_id', $message->id)->count();
    }

    private function assertFailed(NotificationMessage $message, NotificationFailureCode $code, ?string $cause = null): void
    {
        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $stored->status);
        $this->assertSame($code->value, $stored->publish_failure_code);
        $this->assertNotNull($stored->publish_failed_at);
        $this->assertNull($stored->published_at);
        $this->assertSame(0, $this->recipients($message));
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::PUBLISH_FAILED->value)->sole();
        $this->assertSame(['SYSTEM', null, $code->value], [$event->actor_side->value, $event->actor_user_id, $event->reason_code]);
        $metadata = $event->metadata;
        ksort($metadata);
        $this->assertSame(array_filter(['attempt' => $stored->publish_attempts, 'cause' => $cause]), $metadata);
    }

    private function assertUntouchedQueued(NotificationMessage $message): void
    {
        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::QUEUED, $stored->status);
        $this->assertSame(0, $stored->publish_attempts);
        $this->assertNull($stored->publishing_token);
        $this->assertSame(['QUEUED'], $this->events($message));
    }

    // ------------------------------------------------------------- publication

    public function test_a_queued_message_is_claimed_and_published_atomically(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $families = Family::factory()->count(3)->create();
        $message = $this->queued($families);

        $this->assertSame(Result::PUBLISHED, $this->publisher->publish($message->id));

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::PUBLISHED, $stored->status);
        $this->assertSame('2026-10-19 10:00:00', $stored->published_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $stored->recipient_count);
        $this->assertSame(1, $stored->publish_attempts);
        $this->assertTrue(Str::isUuid($stored->publishing_token));
        $this->assertSame(['QUEUED', 'PUBLISHING_STARTED', 'PUBLISHED'], $this->events($message));
        $rows = DB::table('notification_recipients')->where('message_id', $message->id)->orderBy('family_id')->get();
        $this->assertSame($families->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(), $rows->pluck('family_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['ACTIVE'], $rows->pluck('family_status_at_publication')->unique()->values()->all());
        // The snapshot uses the publication timestamp.
        $this->assertSame(['2026-10-19 10:00:00'], $rows->pluck('created_at')->map(fn ($t) => substr((string) $t, 0, 19))->unique()->values()->all());
        $published = NotificationRecipientEvent::query()->where('message_id', $message->id)->where('event_type', 'PUBLISHED')->sole();
        $this->assertSame(['recipient_count' => 3], $published->metadata);
    }

    public function test_overlapping_coordinator_criteria_give_exactly_one_recipient_per_family(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $branch = $this->branch($clan);
        [$a, $b] = [$this->familyIn($clan, $branch), $this->familyIn($clan, $branch)];
        $coordinator = $this->coordinatorSender($branch);
        $draft = $this->draft($coordinator, ['type' => 'COORDINATOR_SELECTION', 'branch_ids' => [$branch->id], 'family_ids' => [$a->id, $b->id]]);
        $message = app(RequestPublicationAction::class)->handle($coordinator, $draft)->message;

        $this->assertSame(Result::PUBLISHED, $this->publisher->publish($message->id));

        $this->assertSame(2, $message->fresh()->recipient_count);
        $this->assertSame([(int) $a->id, (int) $b->id], DB::table('notification_recipients')->where('message_id', $message->id)
            ->orderBy('family_id')->pluck('family_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_system_message_publishes_to_the_requests_family_whatever_its_status(): void
    {
        config(['notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-01-01T00:00:00Z']);
        $request = ChangeRequest::factory()->create();
        Family::query()->whereKey($request->family_id)->update(['status' => FamilyStatus::INACTIVE->value]);
        $event = WorkflowEvent::create(['workflowable_type' => 'change_request', 'workflowable_id' => $request->id, 'from_status' => null,
            'to_status' => 'APPLIED', 'event_type' => 'APPLIED', 'actor_user_id' => null, 'actor_side' => 'SYSTEM']);
        $message = new NotificationMessage;
        $message->forceFill(['kind' => NotificationKind::SYSTEM, 'sender_side' => NotificationSenderSide::SYSTEM, 'title' => 'تم تطبيق طلبك',
            'body' => 'تم تطبيق طلب التحديث.', 'origin_workflow_event_id' => $event->id, 'status' => NotificationStatus::QUEUED, 'queued_at' => now()])->save();

        $this->assertSame(Result::PUBLISHED, $this->publisher->publish($message->id));
        $this->assertSame('INACTIVE', DB::table('notification_recipients')->where('message_id', $message->id)->value('family_status_at_publication'));

        // In PILOT, SYSTEM publication is paused: it requires ON.
        $paused = $message->replicate(['uuid', 'notice_code', 'origin_workflow_event_id', 'published_at', 'recipient_count', 'publishing_started_at', 'publishing_token', 'publish_attempts']);
        $paused->forceFill(['status' => NotificationStatus::QUEUED, 'origin_workflow_event_id' => WorkflowEvent::create([
            'workflowable_type' => 'change_request', 'workflowable_id' => $request->id, 'from_status' => null,
            'to_status' => 'REJECTED', 'event_type' => 'REJECTED', 'actor_user_id' => null, 'actor_side' => 'SYSTEM'])->id])->save();
        config(['notifications.mode' => 'PILOT']);
        $this->assertSame(Result::PAUSED, $this->publisher->publish($paused->id));
        $this->assertSame(0, $paused->fresh()->publish_attempts);
    }

    // -------------------------------------------------------------- T1 claim

    public function test_a_duplicate_delivery_or_a_cancelled_message_is_never_claimed(): void
    {
        $message = $this->queued([Family::factory()->create()]);
        $this->assertSame(Result::PUBLISHED, $this->publisher->publish($message->id));
        $this->assertSame(Result::NOT_CLAIMED, $this->publisher->publish($message->id));
        $this->assertSame(1, $message->fresh()->publish_attempts);
        $this->assertSame(['QUEUED', 'PUBLISHING_STARTED', 'PUBLISHED'], $this->events($message));

        $cancelled = $this->queued([Family::factory()->create()]);
        app(CancelPublicationAction::class)->handle($this->staff, $cancelled);
        $this->assertSame(Result::NOT_CLAIMED, $this->publisher->publish($cancelled->id));
        $this->assertSame(0, $this->recipients($cancelled));
        $this->assertSame(Result::NOT_CLAIMED, $this->publisher->publish(999999));
    }

    public function test_off_pilot_and_invalid_configuration_pause_without_consuming_an_attempt(): void
    {
        $family = Family::factory()->create();
        $message = $this->queued([$family]);

        config(['notifications.mode' => 'OFF']);
        $this->assertSame(Result::PAUSED, $this->publisher->publish($message->id));
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '999999']);
        $this->assertSame(Result::PAUSED, $this->publisher->publish($message->id));
        config(['notifications.mode' => 'ON', 'notifications.automatic_publish_attempts' => 9]);
        $this->assertSame(Result::PAUSED, $this->publisher->publish($message->id));
        config(['notifications.automatic_publish_attempts' => 3, 'queue.connections.database.retry_after' => 60]);
        $this->assertSame(Result::PAUSED, $this->publisher->publish($message->id));
        $this->assertUntouchedQueued($message);

        // An allow-listed sender, to a pilot household (pilot isolation, ADR §2h).
        config(['queue.connections.database.retry_after' => 90, 'notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => (string) $this->staff->id, 'notifications.pilot_family_ids' => (string) $family->id]);
        $this->assertSame(Result::PUBLISHED, $this->publisher->publish($message->id));
    }

    public function test_the_claim_limit_is_never_exceeded(): void
    {
        $message = $this->queued([Family::factory()->create()]);
        $message->forceFill(['publish_attempts' => 4])->save();

        $this->assertSame(Result::PAUSED, $this->publisher->publish($message->id));
        $this->assertSame(4, $message->fresh()->publish_attempts);
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
    }

    // ------------------------------------------------------- T2 refusals → T3

    public function test_a_sender_who_lost_the_send_permission_or_the_account_is_not_published_for(): void
    {
        $family = Family::factory()->create();
        $revoked = $this->queued([$family]);
        $this->staff->revokePermissionTo('announcement.send');
        $this->assertSame(Result::FAILED, $this->publisher->publish($revoked->id));
        $this->assertFailed($revoked, NotificationFailureCode::SENDER_NOT_AUTHORIZED);

        $other = $this->staffSender();
        $inactive = $this->queued([$family], $other);
        $other->forceFill(['is_active' => false])->save();
        $this->assertSame(Result::FAILED, $this->publisher->publish($inactive->id));
        $this->assertFailed($inactive, NotificationFailureCode::SENDER_NOT_AUTHORIZED);
    }

    public function test_send_all_and_coordinator_scope_are_re_checked(): void
    {
        Family::factory()->create();
        $sendAll = $this->staffSender('announcement.create', 'announcement.send', NotificationAudienceResolver::SEND_ALL_PERMISSION);
        $all = app(RequestPublicationAction::class)->handle($sendAll, $this->draft($sendAll, ['type' => 'STAFF_ALL_ACTIVE']))->message;
        $sendAll->revokePermissionTo(NotificationAudienceResolver::SEND_ALL_PERMISSION);
        $this->assertSame(Result::FAILED, $this->publisher->publish($all->id));
        $this->assertFailed($all, NotificationFailureCode::SENDER_NOT_AUTHORIZED);

        $clan = $this->clan('NTF_CLAN');
        [$first, $second] = [$this->branch($clan), $this->branch($clan)];
        $this->familyIn($clan, $first);
        $this->familyIn($clan, $second);
        $coordinator = $this->coordinatorSender($first);
        $extra = $this->assign($coordinator, $second);
        $selection = app(RequestPublicationAction::class)->handle($coordinator,
            $this->draft($coordinator, ['type' => 'COORDINATOR_SELECTION', 'branch_ids' => [$second->id]]))->message;
        $extra->forceFill(['revoked_at' => now(), 'revoked_by' => $this->staff->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertSame(Result::FAILED, $this->publisher->publish($selection->id));
        $this->assertFailed($selection, NotificationFailureCode::SENDER_NOT_AUTHORIZED);
    }

    public function test_an_expired_message_is_never_published(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $message = $this->queued([Family::factory()->create()], input: ['expires_at' => now()->addHours(2)->toIso8601String()]);
        $this->travel(2)->hours();

        $this->assertSame(Result::FAILED, $this->publisher->publish($message->id));
        $this->assertFailed($message, NotificationFailureCode::EXPIRED_BEFORE_PUBLICATION);
        $this->assertSame(NotificationRetryDecision::PERMANENT_FAILURE, app(NotificationRetryPolicy::class)->manual($message->fresh()));
    }

    public function test_zero_recipients_is_audience_empty_and_an_unusable_audience_is_invalid(): void
    {
        $family = Family::factory()->create();
        $empty = $this->queued([$family]);
        $family->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();
        $this->assertSame(Result::FAILED, $this->publisher->publish($empty->id));
        $this->assertFailed($empty, NotificationFailureCode::AUDIENCE_EMPTY);

        $invalid = $this->queued([Family::factory()->create()]);
        $invalid->forceFill(['audience' => ['type' => 'COORDINATOR_SCOPE']])->save();
        $this->assertSame(Result::FAILED, $this->publisher->publish($invalid->id));
        $this->assertFailed($invalid, NotificationFailureCode::AUDIENCE_INVALID);
    }

    public function test_switching_off_between_claim_and_publication_never_publishes_and_stays_recoverable(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $message = $this->queued([Family::factory()->create()]);
        $token = $this->publisher->claim($message->id);
        $this->assertIsString($token);

        config(['notifications.mode' => 'OFF']);
        try {
            $this->publisher->attempt($message->id, $token);
            $this->fail('Expected T2 to refuse.');
        } catch (NotificationPublicationFailure $failure) {
            $this->assertSame(Result::FAILED, $this->publisher->recordFailure($message->id, $token, $failure->failure, $failure->cause));
        }
        $this->assertFailed($message, NotificationFailureCode::PUBLICATION_ERROR, 'FEATURE_OFF');

        // Transient: retried automatically once ON again — no attempt is spent while OFF.
        $this->travel(2)->minutes();
        config(['notifications.mode' => 'ON']);
        $this->assertSame(NotificationRetryDecision::ALLOWED, app(NotificationRetryPolicy::class)->automatic($message->fresh()));
    }

    // ------------------------------------------------- fencing and rollback

    public function test_a_superseded_claim_writes_nothing(): void
    {
        $message = $this->queued([Family::factory()->create()]);
        $token = $this->publisher->claim($message->id);
        $message->forceFill(['publishing_token' => (string) Str::uuid()])->save();

        $this->assertSame(Result::SUPERSEDED, $this->publisher->attempt($message->id, $token));
        $this->assertSame(Result::SUPERSEDED, $this->publisher->recordFailure($message->id, $token, NotificationFailureCode::PUBLICATION_ERROR));
        $this->assertSame(NotificationStatus::PUBLISHING, $message->fresh()->status);
        $this->assertSame(0, $this->recipients($message));
        $this->assertSame(['QUEUED', 'PUBLISHING_STARTED'], $this->events($message));
    }

    public function test_a_failure_after_the_recipient_insert_rolls_t2_back_completely(): void
    {
        Log::spy();
        $message = $this->queued(Family::factory()->count(2)->create());
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipients"')) {
                throw new RuntimeException('failure after the insert, mobile 0591234567');
            }
        });

        $this->assertSame(Result::FAILED, $this->publisher->publish($message->id));

        $this->assertFailed($message, NotificationFailureCode::PUBLICATION_ERROR);
        $this->assertNull($message->fresh()->recipient_count);
        Log::shouldHaveReceived('warning')->withArgs(fn ($text, $context) => $context === [
            'message_id' => $message->id, 'code' => 'PUBLICATION_ERROR', 'exception' => RuntimeException::class,
        ])->once();
    }

    public function test_a_failure_of_t3_leaves_the_claim_for_stale_recovery(): void
    {
        $message = $this->queued([Family::factory()->create()]);
        $token = $this->publisher->claim($message->id);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update "notification_messages"')) {
                throw new RuntimeException('T3 could not write');
            }
        });

        try {
            $this->publisher->recordFailure($message->id, $token, NotificationFailureCode::PUBLICATION_ERROR);
            $this->fail('Expected T3 to throw.');
        } catch (RuntimeException) {
        }
        $this->assertSame(NotificationStatus::PUBLISHING, $message->fresh()->status);
        $this->assertSame(['QUEUED', 'PUBLISHING_STARTED'], $this->events($message));
    }

    // ------------------------------------------------- permissions freshness

    public function test_the_job_reloads_the_permission_map_a_long_running_worker_holds(): void
    {
        // The send permission comes from the sender's ROLE (REPORTS_VIEWER, the
        // fixture's base role; granted here in the test database only).
        $role = Role::findByName('REPORTS_VIEWER', 'web');
        $role->givePermissionTo(Permission::findOrCreate('announcement.send', 'web'));
        $staff = $this->staffSender('announcement.create');
        $message = $this->queued([Family::factory()->create()], $staff);

        // The worker's in-memory map is loaded; another process then removes
        // the permission from the role (the shared cache store is flushed, as
        // Spatie does on every change) — this process's map is now stale.
        $this->assertTrue($staff->fresh()->can('announcement.send'));
        DB::table('role_has_permissions')->where('role_id', $role->id)
            ->where('permission_id', Permission::findByName('announcement.send', 'web')->id)->delete();
        Cache::forget(config('permission.cache.key'));
        $this->assertTrue($staff->fresh()->can('announcement.send'), 'the in-memory map is stale before the job');

        (new PublishNotificationJob($message->id))->handle($this->publisher);

        $this->assertFailed($message, NotificationFailureCode::SENDER_NOT_AUTHORIZED);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ----------------------------------------------------- job and dispatcher

    public function test_the_job_contract(): void
    {
        $job = new PublishNotificationJob(42);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame([42, 'notifications', 1, 1, 60, true], [$job->messageId, $job->queue, $job->tries, $job->maxExceptions, $job->timeout, $job->failOnTimeout]);
        $this->assertSame(['messageId'], array_map(fn ($p) => $p->getName(), (new ReflectionMethod($job, '__construct'))->getParameters()));
    }

    public function test_the_dispatcher_queues_the_job_and_never_runs_it_synchronously(): void
    {
        Queue::fake();
        $message = $this->queued([Family::factory()->create()]);
        $dispatcher = app(QueueNotificationPublicationDispatcher::class);

        $dispatcher->dispatch($message);
        Queue::assertPushedOn('notifications', PublishNotificationJob::class, fn ($job) => $job->messageId === (int) $message->id);

        foreach (['sync', 'null'] as $connection) {
            config(['queue.default' => $connection, 'queue.connections.null' => ['driver' => 'null']]);
            $dispatcher->dispatch($message);
        }
        Queue::assertCount(1);
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
        $this->assertSame(0, $this->recipients($message));
    }

    public function test_a_failed_hand_off_is_logged_safely_and_leaves_the_message_queued(): void
    {
        Log::spy();
        $message = $this->queued([Family::factory()->create()]);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue database unreachable, password=secret'));

        (new QueueNotificationPublicationDispatcher($bus))->dispatch($message);

        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($text, $context) => $context === [
            'message' => $message->uuid, 'code' => 'DISPATCH_FAILED', 'exception' => RuntimeException::class,
        ])->once();
    }

    public function test_incompatible_queue_timing_fails_closed_in_readiness(): void
    {
        $this->assertSame([], NotificationReadiness::infrastructure());
        foreach ([['queue.connections.database.retry_after', 60], ['queue.connections.database.retry_after', null], ['notifications.stale_publishing_seconds', 90]] as [$key, $value]) {
            config([$key => $value]);
            $this->assertSame([NotificationReadinessReason::QUEUE_TIMING_INVALID], NotificationReadiness::infrastructure(), $key);
            config(['queue.connections.database.retry_after' => 90, 'notifications.stale_publishing_seconds' => 600]);
        }
    }
}
