<?php

namespace Tests\Feature\ChangeRequests\Engine;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * PWA-5b row locking on PostgreSQL. A SECOND database session holds a row
 * (the change request, or the Family) the way a parallel operation would;
 * the action under test, run with a short lock_timeout, must WAIT
 * (lock_not_available, 55P03) instead of reading past it — and once the
 * parallel change is committed, the re-read under lock decides correctly
 * (refuse, replay or detect the stale base). The lock order is request →
 * Family → Person; a submission locks its Family.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database); fixtures
 * are committed (DatabaseTruncation) so the second session sees them.
 * Synthetic data only.
 */
class ChangeRequestConcurrencyTest extends TestCase
{
    use ChangeRequestFixtures, DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private FamilyAccessResult $context;

    private User $reviewer;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->setUpChangeRequestEngine();
        $this->context = $this->headContext();
        $this->context->family->forceFill(['paper_form_no' => 'PF-OLD'])->save();
        $this->reviewer = $this->staff();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::connection('other_runner')->disconnect();
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

    private function other(): Connection
    {
        return DB::connection('other_runner');
    }

    /** Runs $operation while the other session holds $table row $id FOR UPDATE; returns what it threw. */
    private function whileLocked(string $table, int $id, Closure $operation): ?Throwable
    {
        $this->other()->beginTransaction();
        $this->other()->select("SELECT id FROM {$table} WHERE id = ? FOR UPDATE", [$id]);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            $operation();

            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            DB::rollBack();
            $this->other()->rollBack();
        }
    }

    private function assertWaited(?Throwable $e): void
    {
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $e->getCode());
    }

    private function refusedWith(callable $call, string $code): void
    {
        try {
            $call();
        } catch (ChangeRequestException $e) {
            $this->assertSame($code, $e->reason);

            return;
        }
        $this->fail("Expected {$code}.");
    }

    /** The other session commits a status change (a parallel reviewer's decision). */
    private function otherCommits(ChangeRequest $request, array $columns): void
    {
        $this->other()->table('change_requests')->where('id', $request->id)->update([...$columns, 'updated_at' => now()]);
    }

    private function underReview(): ChangeRequest
    {
        $request = $this->submit($this->context);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        return $request;
    }

    public function test_a_submission_waits_for_the_family_lock_and_then_sees_the_parallel_one(): void
    {
        $reference = (string) Str::uuid();

        $this->assertWaited($this->whileLocked('families', $this->context->family->id, fn () => $this->submit($this->context, reference: $reference)));
        $this->assertSame(0, ChangeRequest::count());

        // The parallel submission commits first: the same key replays, a new key conflicts.
        $first = $this->submit($this->context, reference: $reference);
        $replay = app(SubmitChangeRequestAction::class)->handle(
            $this->context, new ChangeRequestSubmission(self::FAKE_TYPE, ['paper_form_no' => 'PF-NEW-1'], null, $reference),
        );
        $this->assertTrue($replay->replayed);
        $this->assertTrue($replay->request->is($first));
        $this->refusedWith(fn () => $this->submit($this->context), ChangeRequestException::ALREADY_OPEN);
        $this->assertSame(1, ChangeRequest::count());
    }

    public function test_a_second_start_review_waits_and_then_is_refused_for_another_reviewer(): void
    {
        $request = $this->submit($this->context);

        $this->assertWaited($this->whileLocked('change_requests', $request->id, fn () => app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer)));
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);

        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $this->staff('ADMINISTRATOR')), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::REVIEW_STARTED)->count());
    }

    public function test_approve_waits_for_a_parallel_reject_and_then_refuses(): void
    {
        $request = $this->underReview();

        $this->assertWaited($this->whileLocked('change_requests', $request->id, fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer)));

        $this->otherCommits($request, ['status' => 'REJECTED', 'rejected_by' => $this->reviewer->id, 'rejected_at' => now(), 'rejection_reason_code' => 'CANNOT_VERIFY']);
        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::REJECTED, $request->fresh()->status);
    }

    public function test_cancel_waits_for_a_parallel_approval_and_then_refuses(): void
    {
        $request = $this->underReview();

        $this->assertWaited($this->whileLocked('change_requests', $request->id, fn () => app(CancelChangeRequestAction::class)->handle($this->context, $request)));

        $this->otherCommits($request, ['status' => 'APPROVED', 'approved_by' => $this->reviewer->id, 'approved_at' => now()]);
        $this->refusedWith(fn () => app(CancelChangeRequestAction::class)->handle($this->context, $request), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_a_second_apply_waits_and_then_replays_without_writing_again(): void
    {
        Log::spy();
        $request = $this->underReview();
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        // Waiting on the request lock is not an apply attempt: nothing is recorded.
        $e = $this->whileLocked('change_requests', $request->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $e);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $e->reason);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $c['exception'] === QueryException::class);
        $this->assertSame(0, $request->fresh()->apply_failure_count);
        $this->assertSame(0, FakeChangeRequestHandler::$applied);

        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertTrue(app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer)->replayed);
        $this->assertSame(1, FakeChangeRequestHandler::$applied);
        $this->assertSame('PF-NEW-1', $this->context->family->fresh()->paper_form_no);
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_apply_waits_for_a_parallel_canonical_change_and_then_detects_the_stale_base(): void
    {
        $request = $this->underReview();
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        // The Family row is held by a parallel registry operation: apply waits.
        $e = $this->whileLocked('families', $this->context->family->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $e);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $e->reason);
        $this->assertSame(0, FakeChangeRequestHandler::$applied);

        // That operation commits a newer value: the old request never overwrites it.
        $this->other()->table('families')->where('id', $this->context->family->id)->update(['paper_form_no' => 'PF-NEWER']);
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::BASE_CHANGED);

        $this->assertSame('PF-NEWER', $this->context->family->fresh()->paper_form_no);
        $fresh = $request->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        $this->assertSame(1, $fresh->apply_failure_count);
        $this->assertSame('BASE_CHANGED', WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
    }
}
