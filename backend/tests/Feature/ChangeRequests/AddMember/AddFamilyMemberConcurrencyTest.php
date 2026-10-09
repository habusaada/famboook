<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
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
 * ADD_FAMILY_MEMBER under concurrency on PostgreSQL (docs/11 FP-ADR-076).
 * A SECOND session holds what a parallel operation would — the National ID
 * advisory lock of a concurrent Person creation, or the Person row of a
 * concurrent attachment; APPLY must WAIT (55P03 under a short lock_timeout,
 * which the engine reports as a retryable APPLY_FAILED) instead of reading
 * past it, and after the parallel change commits it must detect the stale
 * base — never a second Person, never a second active membership.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Synthetic
 * data only.
 */
class AddFamilyMemberConcurrencyTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const NID = '401234567';

    private FamilyAccessResult $context;

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
        $this->seed(RelationshipTypeSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
        ]));
        $this->context = $this->contextFor('123456789');
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

    private function contextFor(string $headNationalId): FamilyAccessResult
    {
        return app(FamilyAccessResolver::class)->familyContext($this->activatedHead($headNationalId)['user']);
    }

    private function other(): Connection
    {
        return DB::connection('other_runner');
    }

    /** Runs $operation while the other session holds $hold (inside its transaction); returns what it threw. */
    private function whileHeld(Closure $hold, Closure $operation): ?Throwable
    {
        $this->other()->beginTransaction();
        $hold($this->other());
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

    private function approved(FamilyAccessResult $context): ChangeRequest
    {
        $request = app(SubmitChangeRequestAction::class)->handle($context, new ChangeRequestSubmission(ChangeRequestType::ADD_FAMILY_MEMBER, [
            'full_name' => 'فرد تجريبي', 'national_id' => self::NID, 'gender' => 'MALE', 'relationship' => 'SON',
        ], null, (string) Str::uuid()))->request;
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::NID,
        ));

        return $request->fresh();
    }

    private function applyFails(ChangeRequest $request, string $code): void
    {
        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
            $this->fail("Expected {$code}.");
        } catch (ChangeRequestException $e) {
            $this->assertSame($code, $e->reason);
        }
    }

    public function test_apply_waits_for_a_parallel_creation_of_the_same_id_and_never_duplicates(): void
    {
        $request = $this->approved($this->context);

        // A parallel Staff registration holds the National ID lock (as CreatePersonAction does).
        $waited = $this->whileHeld(
            fn (Connection $other) => $other->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:'.self::NID]),
            fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer),
        );
        $this->assertInstanceOf(ChangeRequestException::class, $waited);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $waited->reason);
        $this->assertSame(0, Person::where('national_id', self::NID)->count());

        // The parallel registration commits a Person with that ID: APPLY now sees the changed base.
        Person::factory()->create(['national_id' => self::NID]);
        $this->applyFails($request, ChangeRequestException::BASE_CHANGED);
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_two_families_attaching_the_same_person_serialize_and_only_one_wins(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID]);
        $mine = $this->approved($this->context);
        $theirs = $this->approved($this->contextFor('223456789'));

        // A parallel attachment holds the Person row: APPLY waits instead of reading past it.
        $waited = $this->whileHeld(
            fn (Connection $other) => $other->select('SELECT id FROM persons WHERE id = ? FOR UPDATE', [$person->id]),
            fn () => app(ApplyChangeRequestAction::class)->handle($mine, $this->reviewer),
        );
        $this->assertInstanceOf(ChangeRequestException::class, $waited);
        $this->assertSame(0, FamilyMembership::where('person_id', $person->id)->count());

        app(ApplyChangeRequestAction::class)->handle($mine, $this->reviewer);
        $this->applyFails($theirs, ChangeRequestException::BASE_CHANGED);

        $this->assertSame(1, FamilyMembership::where('person_id', $person->id)->where('is_active', true)->count());
        $this->assertSame($this->context->family->id, FamilyMembership::where('person_id', $person->id)->sole()->family_id);
    }
}
