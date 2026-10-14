<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\RegisterFamilyHandler;
use Closure;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * CO-02.5 on PostgreSQL: REGISTER_FAMILY submissions and APPLYs that race
 * on the proposed head's National ID. A second session holds the National
 * ID advisory lock; the operation waits (lock_timeout proves it), and once
 * the parallel work is committed the re-read decides correctly — one open
 * proposal per Coordinator and ID, one head Person per ID.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation). Only `clans` (migration reference
 * data) is kept: the relationship types it seeds are truncated afterwards,
 * so nothing it commits outlives it. It creates no Clan. Synthetic data only.
 */
class RegisterFamilyConcurrencyTest extends TestCase
{
    use CoordinatorFixtures, DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const HEAD_NID = '412345678';

    private Clan $scope;

    private User $coordinatorA;

    private User $coordinatorB;

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
        $this->seed(RelationshipTypeSeeder::class);
        config(['change_requests.coordinator_submission_mode' => 'GENERAL']);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::REGISTER_FAMILY->value => new RegisterFamilyHandler,
        ]));
        $this->scope = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $this->coordinatorA = $this->coordinator('111111110');
        $this->assign($this->coordinatorA, $this->scope);
        $this->coordinatorB = $this->coordinator('211111110');
        $this->assign($this->coordinatorB, $this->scope);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
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

    /** Runs $operation while the other session holds the head's National ID lock; returns what it threw. */
    private function whileNationalIdLocked(Closure $operation): ?Throwable
    {
        $this->other()->beginTransaction();
        $this->other()->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:'.self::HEAD_NID]);
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

    private function propose(User $by): ChangeRequestOutcome
    {
        return app(SubmitChangeRequestAction::class)->handleCoordinatorProposal($by->fresh(), $this->scope, null, new ChangeRequestSubmission(
            ChangeRequestType::REGISTER_FAMILY,
            ['household_head' => ['full_name' => 'رب أسرة مقترح', 'national_id' => self::HEAD_NID, 'gender' => 'MALE'],
                'residence' => ['original_residence_text' => 'بلدة تجريبية']],
            null,
            (string) Str::uuid(),
        ));
    }

    private function approved(ChangeRequest $request): ChangeRequest
    {
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::HEAD_NID,
        ));

        return $request->fresh();
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

    public function test_a_submission_waits_for_the_national_id_lock_and_the_next_one_sees_the_first(): void
    {
        $e = $this->whileNationalIdLocked(fn () => $this->propose($this->coordinatorA));
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $e->getCode());
        $this->assertSame(0, ChangeRequest::count());

        $this->propose($this->coordinatorA);
        $this->refusedWith(fn () => $this->propose($this->coordinatorA), ChangeRequestException::ALREADY_OPEN);
        // Never across Coordinators: the other one is not told.
        $this->assertSame(S::SUBMITTED, $this->propose($this->coordinatorB)->request->status);
        $this->assertSame(2, ChangeRequest::count());
        $this->assertFalse(Person::where('national_id', self::HEAD_NID)->exists(), 'nothing is registered before APPLY');
    }

    public function test_competing_applies_wait_on_the_national_id_and_only_one_head_is_created(): void
    {
        Log::spy();
        $first = $this->approved($this->propose($this->coordinatorA)->request);
        $second = $this->approved($this->propose($this->coordinatorB)->request);
        $families = Family::count();

        // APPLY takes the head's National ID lock before registering anything.
        $e = $this->whileNationalIdLocked(fn () => app(ApplyChangeRequestAction::class)->handle($first, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $e);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $c['exception'] === QueryException::class);
        $this->assertSame($families, Family::count());

        app(ApplyChangeRequestAction::class)->handle($first, $this->reviewer);
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($second, $this->reviewer), ChangeRequestException::BASE_CHANGED);

        $this->assertSame(1, Person::where('national_id', self::HEAD_NID)->count());
        $this->assertSame($families + 1, Family::count());
        $this->assertNotNull($first->fresh()->created_family_id);
        $this->assertSame([S::APPROVED, null], [$second->fresh()->status, $second->fresh()->created_family_id]);
    }
}
