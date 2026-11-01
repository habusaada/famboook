<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Enums\FamilyMemberIdentityOutcome as Outcome;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\FamilyMemberIdentity;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * FamilyMemberIdentity's locks on PostgreSQL (FB-FAMILY-ADD-01 WP-03): a SECOND
 * session holds what a parallel operation would; resolve() must wait (55P03 under
 * a short lock_timeout), not read past it, and must not lock the row of a Person
 * who is active in a Family.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Synthetic data only.
 */
class FamilyMemberIdentityConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const NID = '401234567';

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
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

    /** Runs resolve() while the other session holds $hold; returns the resolution or what it threw. */
    private function resolveWhileHeld(Closure $hold): mixed
    {
        $other = DB::connection('other_runner');
        $other->beginTransaction();
        $hold($other);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            return FamilyMemberIdentity::resolve(self::NID);
        } catch (QueryException $e) {
            return $e;
        } finally {
            DB::rollBack();
            $other->rollBack();
        }
    }

    private function assertWaited(mixed $result): void
    {
        $this->assertInstanceOf(QueryException::class, $result);
        $this->assertSame('55P03', $result->getCode());
    }

    public function test_it_waits_for_a_parallel_holder_of_the_national_id_lock(): void
    {
        $this->assertWaited($this->resolveWhileHeld(
            fn (Connection $other) => $other->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:'.self::NID]),
        ));
        $this->assertSame(Outcome::NEW_PERSON, DB::transaction(fn () => FamilyMemberIdentity::resolve(self::NID))->outcome);
    }

    public function test_it_waits_for_a_parallel_lock_on_an_unattached_persons_row(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID]);

        $this->assertWaited($this->resolveWhileHeld(
            fn (Connection $other) => $other->select('SELECT id FROM persons WHERE id = ? FOR UPDATE', [$person->id]),
        ));
    }

    public function test_it_refuses_an_active_member_without_waiting_for_their_row(): void
    {
        $person = Person::factory()->create(['national_id' => self::NID]);
        FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'person_id' => $person->id, 'is_active' => true]);

        $result = $this->resolveWhileHeld(
            fn (Connection $other) => $other->select('SELECT id FROM persons WHERE id = ? FOR UPDATE', [$person->id]),
        );

        $this->assertSame(Outcome::ACTIVE_MEMBERSHIP, $result->outcome);
    }
}
