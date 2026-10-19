<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationPublicationResult as Result;
use App\Enums\NotificationStatus;
use App\Models\Clan;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationPublicationReconciler;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.5d on PostgreSQL: the reconciler against real concurrent
 * sessions (tests/Support/Notifications/publication-actor.php). FOR UPDATE
 * SKIP LOCKED never waits for a held row; two reconcilers never requeue a
 * message twice; a live worker is never recovered as stale; a stale claim is
 * recovered before its late worker can write; a redispatch racing the worker
 * never duplicates recipients.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation) in the migration's AL_BREEM Clan.
 * Synthetic data only.
 */
class NotificationReconciliationConcurrencyTest extends TestCase
{
    use DatabaseTruncation, NotificationActionFixtures, NotificationActorProcesses;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private User $staff;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->setUpNotificationActions();
        $this->staff = $this->staffSender();
        $this->exportActorEnvironment();
    }

    protected function tearDown(): void
    {
        $this->stopActors();
        if (isset($this->app)) {
            $this->resetConcurrencyDatabase();
        }
        parent::tearDown();
    }

    private function queued(): NotificationMessage
    {
        $clan = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $audience = ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->familyIn($clan)->id, $this->familyIn($clan)->id]];

        return app(RequestPublicationAction::class)->handle($this->staff, $this->draft($this->staff, $audience))->message->fresh();
    }

    /** A transient failure whose backoff passed 10 minutes ago (by the real clock, which the actors use). */
    private function retryable(): NotificationMessage
    {
        $message = $this->failPublication($this->queued(), NotificationFailureCode::PUBLICATION_ERROR, 1);
        $message->forceFill(['publish_failed_at' => now()->subMinutes(10)])->save();

        return $message->fresh();
    }

    private function reconcile(): array
    {
        return app(NotificationPublicationReconciler::class)->run()->toArray();
    }

    private function requeueEvents(NotificationMessage $message): int
    {
        return NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->count();
    }

    private function recipients(NotificationMessage $message): int
    {
        return DB::table('notification_recipients')->where('message_id', $message->id)->count();
    }

    public function test_skip_locked_never_waits_for_a_row_held_by_another_session(): void
    {
        $message = $this->retryable();
        $gate = $this->spawn('hold', (string) $message->id);

        $started = microtime(true);
        $report = $this->reconcile();
        $this->assertLessThan(2.0, microtime(true) - $started, 'the reconciler waited for the held row');
        $this->assertSame([1, 0], [$report['skipped'], $report['automatic_requeued']]);
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);

        $this->go($gate);
        $this->assertSame('OK', $this->lineOf($gate));
        $this->assertSame(1, $this->reconcile()['automatic_requeued']);
        $this->assertSame(1, $this->requeueEvents($message));
    }

    public function test_two_concurrent_reconcilers_requeue_each_message_exactly_once(): void
    {
        $messages = collect(range(1, 6))->map(fn () => $this->retryable());
        $first = $this->spawn('reconcile', '100');
        $second = $this->spawn('reconcile', '100');
        $this->go($first);
        $this->go($second);

        $reports = array_map(fn (int $child) => json_decode(substr($this->lineOf($child), 3), true), [$first, $second]);

        $this->assertSame(6, $reports[0]['automatic_requeued'] + $reports[1]['automatic_requeued']);
        $this->assertSame([0, 0], [$reports[0]['errors'], $reports[1]['errors']]);
        foreach ($messages as $message) {
            $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
            $this->assertSame(1, $this->requeueEvents($message), 'a message was requeued twice');
        }
    }

    public function test_a_live_worker_is_never_recovered_as_stale(): void
    {
        $message = $this->queued();
        $worker = $this->spawn('publish-pause', (string) $message->id);
        $this->go($worker);
        $this->assertSame('INSERTED', $this->lineOf($worker));

        // Eleven minutes "later" in this session: the claim looks stale, but
        // the worker's T2 holds the row — SKIP LOCKED leaves it alone.
        $this->travel(11)->minutes();
        $report = $this->reconcile();
        $this->assertSame([0, 1], [$report['stale_recovered'], $report['skipped']]);

        $this->go($worker);
        $this->assertSame('OK '.Result::PUBLISHED->value, $this->lineOf($worker));
        $this->assertSame(0, $this->reconcile()['stale_recovered']);
        $this->assertSame([NotificationStatus::PUBLISHED, 2], [$message->fresh()->status, $this->recipients($message)]);
    }

    public function test_a_stale_claim_is_recovered_before_its_late_worker_can_write(): void
    {
        $message = $this->queued();
        $worker = $this->spawn('claim-then-attempt', (string) $message->id);
        $this->go($worker);
        $this->assertSame('CLAIMED', $this->lineOf($worker));

        $this->travel(601)->seconds();
        $this->assertSame(1, $this->reconcile()['stale_recovered']);

        $this->go($worker);
        $this->assertSame('OK '.Result::SUPERSEDED->value, $this->lineOf($worker));
        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::PUBLISH_FAILED, 'STALE_PUBLISHING', null, 1],
            [$stored->status, $stored->publish_failure_code, $stored->publishing_token, $stored->publish_attempts]);
        $this->assertSame(0, $this->recipients($message));

        // Recovered: requeued automatically after the backoff, then published once.
        $this->travel(61)->seconds();
        $this->freshHeartbeat();
        $this->assertSame(1, $this->reconcile()['automatic_requeued']);
        $this->assertSame(Result::PUBLISHED, app(NotificationPublisher::class)->publish($message->id));
        $this->assertSame([NotificationStatus::PUBLISHED, 2, 2], [$message->fresh()->status, $message->fresh()->publish_attempts, $this->recipients($message)]);
    }

    public function test_a_redispatch_racing_the_worker_never_duplicates_recipients(): void
    {
        $message = $this->queued();
        $message->forceFill(['queued_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)])->saveQuietly();
        $worker = $this->spawn('publish', (string) $message->id);
        $reconciler = $this->spawn('reconcile', '100');
        $this->go($worker);
        $this->go($reconciler);

        $this->assertSame('OK '.Result::PUBLISHED->value, $this->lineOf($worker));
        $report = json_decode(substr($this->lineOf($reconciler), 3), true);
        $this->assertSame(0, $report['errors']);

        // Whatever the reconciler handed off, a second worker finds nothing to claim.
        $this->assertSame(Result::NOT_CLAIMED, app(NotificationPublisher::class)->publish($message->id));
        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::PUBLISHED, 1, 2, 2], [$stored->status, $stored->publish_attempts, $stored->recipient_count, $this->recipients($message)]);
    }
}
