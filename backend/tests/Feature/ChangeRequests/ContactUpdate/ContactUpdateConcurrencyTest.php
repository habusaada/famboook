<?php

namespace Tests\Feature\ChangeRequests\ContactUpdate;

use App\Actions\ContactUpdate\CompleteContactUpdateAction;
use App\Actions\ContactUpdate\StartContactUpdateAction;
use App\Contracts\SmsSender;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Exceptions\FamilyAuthException;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\ContactUpdateHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * CONTACT_UPDATE under concurrency on PostgreSQL (docs/11 FP-ADR-079): the
 * completion locks request → Family → Person, so it WAITS for a parallel
 * Staff write to the member (55P03 under a short lock_timeout — nothing
 * changed, the request stays SUBMITTED) and, once that write commits,
 * refuses the stale proposal and closes the request instead of
 * overwriting the newer number. A completion also waits for a parallel one
 * on the same request, so a request applies once. It runs against the real
 * CHECK constraints (SYSTEM approval and application).
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Synthetic
 * data only.
 */
class ContactUpdateConcurrencyTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const NEW_MOBILE = '0561112233';

    private FamilyAccessResult $context;

    private Person $member;

    private FamilyMembership $membership;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['change_requests.family_submission_mode' => 'GENERAL', 'family_auth.contact_update_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::CONTACT_UPDATE->value => new ContactUpdateHandler,
        ]));
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->member = Person::factory()->create(['mobile' => '0597654321']);
        $this->membership = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'person_id' => $this->member->id, 'is_active' => true, 'is_household_head' => false]);
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
        try {
            $operation();

            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            $other->rollBack();
        }
    }

    private function started(): ChangeRequest
    {
        return app(StartContactUpdateAction::class)->handle($this->context, [
            'member_ref' => HouseholdMemberReference::of($this->membership->family_id, $this->membership->id),
            'mobile' => self::NEW_MOBILE,
        ], null, (string) Str::uuid())['request'];
    }

    private function complete(ChangeRequest $request): array
    {
        return app(CompleteContactUpdateAction::class)->handle($this->context, $request, (string) $this->sms->lastCode(), null);
    }

    /**
     * A successful completion. Under the test runner the OTP service treats
     * transaction depth 1 as "no caller transaction" (RefreshDatabase wraps
     * every test); this truncating test has no wrapper, so it opens one — as
     * the other PostgreSQL tests do.
     */
    private function completeCommitted(ChangeRequest $request): array
    {
        DB::beginTransaction();
        try {
            $result = $this->complete($request);
            DB::commit();

            return $result;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function test_completion_waits_for_a_parallel_member_write_and_never_overwrites_it(): void
    {
        $request = $this->started();
        DB::statement("SET lock_timeout = '300ms'");

        $waited = $this->whileLocked('persons', $this->member->id, fn () => $this->complete($request));
        $this->assertInstanceOf(QueryException::class, $waited);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $waited->getCode());
        $this->assertSame('0597654321', $this->member->fresh()->mobile);
        $this->assertSame(S::SUBMITTED, $request->fresh()->status, 'a lock wait changes nothing');

        // The Staff write commits first: the stale proposal is refused and closed.
        DB::connection('other_runner')->table('persons')->where('id', $this->member->id)->update(['mobile' => '0591110000']);
        try {
            $this->complete($request);
            $this->fail('A stale contact update was applied.');
        } catch (FamilyAuthException $e) {
            $this->assertSame('CONTACT_UPDATE_FAILED', $e->error->value);
        }
        $this->assertSame('0591110000', $this->member->fresh()->mobile);
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
    }

    public function test_a_parallel_completion_waits_and_the_request_applies_once(): void
    {
        $request = $this->started();
        DB::statement("SET lock_timeout = '300ms'");

        $waited = $this->whileLocked('change_requests', $request->id, fn () => $this->complete($request));
        $this->assertInstanceOf(QueryException::class, $waited);
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);

        $first = $this->completeCommitted($request);
        $this->assertFalse($first['outcome']->replayed);
        $this->assertSame(S::APPLIED, $request->fresh()->status);
        $this->assertTrue($this->completeCommitted($request)['outcome']->replayed);
        $this->assertSame(self::NEW_MOBILE, $this->member->fresh()->mobile);
        // SYSTEM approval and application pass the database's own rules.
        $this->assertSame([null, null], [$request->fresh()->approved_by, $request->fresh()->applied_by]);
    }
}
