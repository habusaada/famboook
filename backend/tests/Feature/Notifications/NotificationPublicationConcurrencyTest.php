<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Actions\RevokeCoordinatorScopeAction;
use App\Enums\CoordinatorRevokeReason;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationPublicationResult as Result;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationPublicationException;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;
use Throwable;

/**
 * FB-NOTIFY-01B.2b.5c on PostgreSQL: the publication worker against real
 * concurrent sessions. Worker steps run in their own PHP process and session
 * (tests/Support/Notifications/publication-actor.php); the test process is
 * the other session. lock_timeout (55P03) proves who waits for whom.
 *
 * Lock order: notification_messages row, then the coordinator's
 * coordinator_scope_assignments rows (FOR SHARE).
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation); the scope is the migration's AL_BREEM
 * Clan, so nothing committed outlives the test. Synthetic data only.
 */
class NotificationPublicationConcurrencyTest extends TestCase
{
    use DatabaseTruncation, NotificationActionFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const ACTOR = __DIR__.'/../../Support/Notifications/publication-actor.php';

    private const LOCK_NOT_AVAILABLE = '55P03';

    private User $staff;

    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->setUpNotificationActions();
        $this->staff = $this->staffSender();
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'), 'DB_URL' => '', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array'] as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child['process']);
            proc_close($child['process']);
        }
        if (isset($this->app)) {
            DB::statement('RESET lock_timeout');
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'),
                $env('DB_PASSWORD'),
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    // ------------------------------------------------------------- harness

    /** Starts an actor and waits for its READY line. */
    private function spawn(string ...$args): int
    {
        $process = proc_open([PHP_BINARY, self::ACTOR, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        $this->children[] = ['process' => $process, 'pipes' => $pipes];
        $ready = $this->lineOf(count($this->children) - 1);
        if ($ready !== 'READY') {
            $this->fail("actor did not start ({$ready}): ".stream_get_contents($pipes[2]));
        }

        return count($this->children) - 1;
    }

    /** Lets a READY actor proceed after $milliseconds (a holder then commits); also resumes a paused publisher. */
    private function go(int $child, int $milliseconds = 0): void
    {
        fwrite($this->children[$child]['pipes'][0], $milliseconds.PHP_EOL);
        fflush($this->children[$child]['pipes'][0]);
    }

    private function lineOf(int $child): string
    {
        return trim((string) fgets($this->children[$child]['pipes'][1]));
    }

    private function family(): Family
    {
        return $this->familyIn(Clan::where('code', Clan::AL_BREEM)->firstOrFail());
    }

    private function queued(?User $sender = null, ?array $audience = null): NotificationMessage
    {
        $sender ??= $this->staff;
        $audience ??= ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family()->id, $this->family()->id]];

        return app(RequestPublicationAction::class)->handle($sender, $this->draft($sender, $audience))->message->fresh();
    }

    private function recipients(NotificationMessage $message): int
    {
        return DB::table('notification_recipients')->where('message_id', $message->id)->count();
    }

    /** Runs $operation in this session with a short lock_timeout; returns what it threw. */
    private function withShortLockTimeout(callable $operation): ?Throwable
    {
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            $operation();
            DB::commit();

            return null;
        } catch (Throwable $e) {
            DB::rollBack();

            return $e;
        }
    }

    private function assertWaitedForALock(?Throwable $e): void
    {
        $this->assertInstanceOf(QueryException::class, $e, 'the operation did not wait for the lock');
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode());
    }

    // --------------------------------------------------------------- tests

    public function test_two_workers_claim_one_message_once(): void
    {
        $message = $this->queued();
        $gate = $this->spawn('hold', (string) $message->id);
        $first = $this->spawn('publish', (string) $message->id);
        $second = $this->spawn('publish', (string) $message->id);
        $this->go($first);
        $this->go($second);
        $this->go($gate, 1000);

        $outcomes = [$this->lineOf($first), $this->lineOf($second)];
        $this->assertSame('OK', $this->lineOf($gate));
        sort($outcomes);
        $this->assertSame(['OK '.Result::NOT_CLAIMED->value, 'OK '.Result::PUBLISHED->value], $outcomes);

        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::PUBLISHED, 1, 2], [$stored->status, $stored->publish_attempts, $stored->recipient_count]);
        $this->assertSame(2, $this->recipients($message));
        $this->assertSame(1, NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::PUBLISHING_STARTED->value)->count());
    }

    public function test_recipients_are_invisible_and_cancellation_waits_until_t2_commits(): void
    {
        $message = $this->queued();
        $worker = $this->spawn('publish-pause', (string) $message->id);
        $this->go($worker);
        $this->assertSame('INSERTED', $this->lineOf($worker));

        // T2 has inserted the recipients but not committed: nothing is visible.
        $this->assertSame(0, $this->recipients($message));
        $this->assertSame(NotificationStatus::PUBLISHING, $message->fresh()->status);
        // A cancellation queues behind T2's message lock.
        $this->assertWaitedForALock($this->withShortLockTimeout(fn () => app(CancelPublicationAction::class)->handle($this->staff, $message)));

        $this->go($worker);
        $this->assertSame('OK '.Result::PUBLISHED->value, $this->lineOf($worker));
        $this->assertSame(2, $this->recipients($message));
        $this->assertSame(NotificationStatus::PUBLISHED, $message->fresh()->status);

        // Now published: the cancellation is refused, never applied.
        try {
            app(CancelPublicationAction::class)->handle($this->staff, $message);
            $this->fail('A published message must not be cancelled.');
        } catch (NotificationPublicationException $e) {
            $this->assertSame(NotificationPublicationException::INVALID_TRANSITION, $e->reason);
        }
    }

    public function test_cancellation_racing_publication_has_exactly_one_outcome(): void
    {
        $message = $this->queued();
        $gate = $this->spawn('hold', (string) $message->id);
        $worker = $this->spawn('publish', (string) $message->id);
        $this->go($worker);
        usleep(300_000);
        $this->go($gate, 700);

        DB::statement("SET lock_timeout = '20s'");
        try {
            app(CancelPublicationAction::class)->handle($this->staff, $message);
            $cancelled = true;
        } catch (NotificationPublicationException $e) {
            $this->assertSame(NotificationPublicationException::INVALID_TRANSITION, $e->reason);
            $cancelled = false;
        }
        $outcome = $this->lineOf($worker);

        $stored = $message->fresh();
        if ($cancelled) {
            $this->assertSame(NotificationStatus::CANCELLED, $stored->status);
            $this->assertSame('OK '.Result::NOT_CLAIMED->value, $outcome);
            $this->assertSame(0, $this->recipients($message));
        } else {
            $this->assertSame(NotificationStatus::PUBLISHED, $stored->status);
            $this->assertSame('OK '.Result::PUBLISHED->value, $outcome);
            $this->assertSame(2, $this->recipients($message));
        }
        $this->assertSame(0, NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', $cancelled ? 'PUBLISHED' : 'CANCELLED')->count());
    }

    public function test_a_revocation_waits_for_an_in_flight_publication(): void
    {
        $coordinator = $this->coordinatorSender(Clan::where('code', Clan::AL_BREEM)->firstOrFail());
        $this->family();
        $message = $this->queued($coordinator, ['type' => 'COORDINATOR_SCOPE']);
        $assignment = CoordinatorScopeAssignment::query()->where('user_id', $coordinator->id)->sole();
        $worker = $this->spawn('publish-pause', (string) $message->id);
        $this->go($worker);
        $this->assertSame('INSERTED', $this->lineOf($worker));

        // T2 holds the assignment FOR SHARE: the revocation must wait.
        $revoke = fn () => app(RevokeCoordinatorScopeAction::class)->handle($this->staff, $assignment, CoordinatorRevokeReason::ADMINISTRATIVE);
        $this->assertWaitedForALock($this->withShortLockTimeout($revoke));

        $this->go($worker);
        $this->assertSame('OK '.Result::PUBLISHED->value, $this->lineOf($worker));
        $revoke();
        $this->assertNotNull($assignment->fresh()->revoked_at);
    }

    public function test_a_revocation_committed_first_refuses_the_publication(): void
    {
        $coordinator = $this->coordinatorSender(Clan::where('code', Clan::AL_BREEM)->firstOrFail());
        $this->family();
        $message = $this->queued($coordinator, ['type' => 'COORDINATOR_SCOPE']);
        $assignment = CoordinatorScopeAssignment::query()->where('user_id', $coordinator->id)->sole();

        // The revocation is in flight (row locked, revoked, not yet committed);
        // the worker claims, then T2 waits on the assignment and sees it revoked.
        $revocation = $this->spawn('hold-revoke', (string) $assignment->id, (string) $this->staff->id);
        $worker = $this->spawn('publish', (string) $message->id);
        $this->go($worker);
        $this->go($revocation, 1000);

        $this->assertSame('OK', $this->lineOf($revocation));
        $this->assertSame('OK '.Result::FAILED->value, $this->lineOf($worker));
        $stored = $message->fresh();
        $this->assertSame([NotificationStatus::PUBLISH_FAILED, NotificationFailureCode::SENDER_NOT_AUTHORIZED->value],
            [$stored->status, $stored->publish_failure_code]);
        $this->assertSame(0, $this->recipients($message));
    }

    public function test_a_failure_after_the_insert_in_a_real_transaction_leaves_no_recipient(): void
    {
        $message = $this->queued();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipients"')) {
                throw new RuntimeException('failure after the insert');
            }
        });

        $this->assertSame(0, DB::transactionLevel(), 'T2 must be a real top-level transaction here');
        $this->assertSame(Result::FAILED, app(NotificationPublisher::class)->publish($message->id));

        $this->assertSame(0, $this->recipients($message));
        $this->assertSame([NotificationStatus::PUBLISH_FAILED, NotificationFailureCode::PUBLICATION_ERROR->value],
            [$message->fresh()->status, $message->fresh()->publish_failure_code]);
    }
}
