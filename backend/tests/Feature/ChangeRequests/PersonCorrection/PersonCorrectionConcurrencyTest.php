<?php

namespace Tests\Feature\ChangeRequests\PersonCorrection;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\PersonCorrectionHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * PERSON_CORRECTION under concurrency on PostgreSQL: APPLY locks the target
 * Person (engine order Family → Person), so it WAITS for a parallel write to
 * that Person (55P03 under a short lock_timeout, reported as a retryable
 * APPLY_FAILED) and, once the parallel write commits, refuses the changed
 * base instead of overwriting it; a second submission for the same member
 * waits for the Family lock and then meets the open request.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Synthetic
 * data only.
 */
class PersonCorrectionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private FamilyAccessResult $context;

    private Person $member;

    private FamilyMembership $membership;

    private User $reviewer;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::PERSON_CORRECTION->value => new PersonCorrectionHandler,
        ]));
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->member = Person::factory()->create(['full_name' => 'سارة الاختبار', 'gender' => Gender::FEMALE, 'marital_status' => MaritalStatus::SINGLE, 'birth_date' => '2005-03-01']);
        $this->membership = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'person_id' => $this->member->id, 'is_active' => true, 'is_household_head' => false]);
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

    /** Runs $operation while the other session holds $table row $id FOR UPDATE; returns what it threw. */
    private function whileLocked(string $table, int $id, Closure $operation): ?Throwable
    {
        $other = DB::connection('other_runner');
        $other->beginTransaction();
        $other->select("SELECT id FROM {$table} WHERE id = ? FOR UPDATE", [$id]);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            $operation();

            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            DB::rollBack();
            $other->rollBack();
        }
    }

    private function submit(string $name): ChangeRequest
    {
        return app(SubmitChangeRequestAction::class)->handle($this->context, new ChangeRequestSubmission(ChangeRequestType::PERSON_CORRECTION, [
            'member_ref' => HouseholdMemberReference::of($this->membership->family_id, $this->membership->id),
            'full_name' => $name, 'birth_date' => '2005-03-01', 'gender' => 'FEMALE', 'marital_status' => 'SINGLE',
        ], null, (string) Str::uuid()))->request;
    }

    public function test_apply_waits_for_a_parallel_person_write_and_never_overwrites_it(): void
    {
        $request = $this->submit('سارة محمد');
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        $waited = $this->whileLocked('persons', $this->member->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $waited);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $waited->reason);
        $this->assertSame('سارة الاختبار', $this->member->fresh()->full_name);

        DB::connection('other_runner')->table('persons')->where('id', $this->member->id)->update(['full_name' => 'سارة أحمد']);
        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
            $this->fail('A stale correction was applied.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::BASE_CHANGED, $e->reason);
        }
        $this->assertSame('سارة أحمد', $this->member->fresh()->full_name);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_a_second_submission_for_the_same_member_waits_and_meets_the_open_request(): void
    {
        $waited = $this->whileLocked('families', $this->context->family->id, fn () => $this->submit('سارة أ'));
        $this->assertInstanceOf(QueryException::class, $waited);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $waited->getCode());
        $this->assertSame(0, ChangeRequest::count());

        $this->submit('سارة أ');
        try {
            $this->submit('سارة ب');
            $this->fail('A second open correction for the same member was created.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::ALREADY_OPEN, $e->reason);
        }
        $this->assertSame(1, ChangeRequest::count());
    }
}
