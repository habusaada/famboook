<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\Family;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\ChangeRequests\FakeProposedFamilyHandler;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * CO-02.3b on PostgreSQL: Coordinator and Staff transitions that race. A
 * second session holds the row a transition needs; the transition waits
 * (lock_timeout proves it), and once the parallel change is committed the
 * re-read under the lock decides correctly — refuse, or replay without a
 * second Family. Lock order for a proposed Family: request → Clan → Branch.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation). It creates no Clan and seeds no
 * relationship type — the scope is the migration's AL_BREEM Clan, restored
 * if changed — so nothing it commits outlives it. Synthetic data only.
 */
class CoordinatorRequestConcurrencyTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private User $reviewer;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        config(['change_requests.coordinator_submission_mode' => 'GENERAL']);
        FakeProposedFamilyHandler::reset();
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::REGISTER_FAMILY->value => new FakeProposedFamilyHandler,
        ]));
        $this->scopeClan = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $this->coordinatorUser = $this->coordinator('111111110');
        $this->assign($this->coordinatorUser, $this->scopeClan);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::connection('other_runner')->disconnect();
            Clan::where('code', Clan::AL_BREEM)->update(['is_active' => true]);
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

    private function underReview(): ChangeRequest
    {
        $request = $this->proposedFamily();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        return $request;
    }

    public function test_a_coordinator_cancellation_waits_for_a_parallel_approval_and_then_refuses(): void
    {
        $request = $this->underReview();
        $cancel = fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request);

        $e = $this->whileLocked('change_requests', $request->id, $cancel);
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $e->getCode());

        $this->other()->table('change_requests')->where('id', $request->id)
            ->update(['status' => 'APPROVED', 'approved_by' => $this->reviewer->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->refusedWith($cancel, ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_a_staff_rejection_of_a_returned_request_waits_for_a_parallel_reply_and_then_refuses(): void
    {
        // CO-02.7b: RETURNED_FOR_CLARIFICATION → REJECTED races the Coordinator's reply.
        $request = $this->underReview();
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'وضّح.');
        $reject = fn () => app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, ChangeRequestRejectionReason::CANNOT_VERIFY);

        $e = $this->whileLocked('change_requests', $request->id, $reject);
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $e->getCode());

        $this->other()->table('change_requests')->where('id', $request->id)->update(['status' => 'RESUBMITTED', 'updated_at' => now()]);
        $this->refusedWith($reject, ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::RESUBMITTED, $request->fresh()->status);
        $this->assertNull($request->fresh()->rejected_at);
    }

    public function test_a_second_apply_of_a_proposal_waits_and_then_replays_without_a_second_family(): void
    {
        Log::spy();
        $request = $this->underReview();
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
        $families = Family::count();

        // Waiting on the request lock is not an apply attempt: nothing is recorded or created.
        $e = $this->whileLocked('change_requests', $request->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $e);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $e->reason);
        $this->assertSame(0, $request->fresh()->apply_failure_count);
        $this->assertSame($families, Family::count());

        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertTrue(app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer)->replayed);
        $this->assertSame($families + 1, Family::count());
        $this->assertSame(1, FakeProposedFamilyHandler::$applied);
        $this->assertNotNull($request->fresh()->created_family_id);
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_apply_waits_for_a_parallel_change_of_the_proposed_clan_and_then_refuses(): void
    {
        Log::spy();
        $request = $this->underReview();
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
        $families = Family::count();

        // The placement is locked (shared) under the request: a writer on the Clan blocks it.
        $e = $this->whileLocked('clans', $this->scopeClan->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $e);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $c['exception'] === QueryException::class);
        $this->assertSame($families, Family::count());

        $this->other()->table('clans')->where('id', $this->scopeClan->id)->update(['is_active' => false]);
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::NOT_APPLICABLE);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertNull($request->fresh()->created_family_id);
        $this->assertSame($families, Family::count());
    }
}
