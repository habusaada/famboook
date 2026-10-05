<?php

namespace Tests\Feature\Families;

use App\Actions\RecordHouseholdDeclarationAction;
use App\Exceptions\HouseholdDeclarationException;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * FU-10: RecordHouseholdDeclarationAction under parallel writers. A SECOND
 * database session plays the parallel Staff member:
 *
 * - while it holds the Family row, a declaration WAITS for the lock
 *   (lock_not_available, 55P03, with a short lock_timeout) instead of
 *   reading past it;
 * - once it has committed a newer declaration, a write still expecting the
 *   older one is refused (HOUSEHOLD_DECLARATION_CHANGED) — exactly one
 *   current declaration, nothing overwritten;
 * - the partial unique index uq_family_current_household_declaration stays
 *   the final guard against a second current row (unique_violation, 23505).
 *
 * PostgreSQL ONLY, against the dedicated `_test` database of
 * phpunit.pgsql.xml; skipped before anything connects otherwise. Fixtures
 * are committed (DatabaseTruncation), because a second session cannot see
 * uncommitted rows. Synthetic data only.
 */
class HouseholdDeclarationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const UNIQUE_VIOLATION = '23505';

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
            // Committed rows must not reach the next test class.
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

    private function declare(Family $family, int $size, ?int $expectedCurrentId): FamilyHouseholdDeclaration
    {
        return app(RecordHouseholdDeclarationAction::class)->handle($family, [
            'declared_household_size' => $size,
            'source' => 'MANUAL_ENTRY',
        ], $expectedCurrentId, null);
    }

    /** @return list<array{declared_household_size: int, is_current: bool}> */
    private function rows(Family $family): array
    {
        return FamilyHouseholdDeclaration::where('family_id', $family->id)->orderBy('id')->get()
            ->map->only(['declared_household_size', 'is_current'])->all();
    }

    /** Runs $operation while the other session holds the Family row FOR UPDATE; returns the SQLSTATE it ended with. */
    private function whileFamilyLocked(Family $family, Closure $operation): ?string
    {
        $this->other()->beginTransaction();
        $this->other()->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$family->id]);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            $operation();

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        } finally {
            DB::rollBack();
            $this->other()->rollBack();
        }
    }

    public function test_a_declaration_waits_for_a_parallel_lock_on_the_family(): void
    {
        $family = Family::factory()->create();
        $current = $this->declare($family, 5, null);

        $state = $this->whileFamilyLocked($family, fn () => $this->declare($family, 6, $current->id));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame([['declared_household_size' => 5, 'is_current' => true]], $this->rows($family), 'The waiting write changed nothing.');
        // Once released it runs, exactly once.
        $this->declare($family, 6, $current->id);
        $this->assertSame([
            ['declared_household_size' => 5, 'is_current' => false],
            ['declared_household_size' => 6, 'is_current' => true],
        ], $this->rows($family));
    }

    public function test_a_declaration_committed_by_a_parallel_session_wins_and_the_stale_write_is_refused(): void
    {
        $family = Family::factory()->create();
        $seen = $this->declare($family, 5, null);
        // The parallel session records a newer declaration and commits first,
        // the way the action does: Family lock, retire the current, insert.
        $this->other()->transaction(function () use ($family, $seen) {
            $this->other()->select('SELECT id FROM families WHERE id = ? FOR UPDATE', [$family->id]);
            $this->other()->table('family_household_declarations')->where('id', $seen->id)->update(['is_current' => false]);
            $this->other()->table('family_household_declarations')->insert([
                'family_id' => $family->id, 'declared_household_size' => 6, 'source' => 'MANUAL_ENTRY',
                'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $this->declare($family, 9, $seen->id);
            $this->fail('Expected HOUSEHOLD_DECLARATION_CHANGED.');
        } catch (HouseholdDeclarationException $e) {
            $this->assertSame(HouseholdDeclarationException::HOUSEHOLD_DECLARATION_CHANGED, $e->reason);
        }

        $this->assertSame([
            ['declared_household_size' => 5, 'is_current' => false],
            ['declared_household_size' => 6, 'is_current' => true],
        ], $this->rows($family));
    }

    public function test_two_first_declarations_cannot_both_become_current(): void
    {
        $family = Family::factory()->create();
        $this->declare($family, 5, null);

        // A second "first" declaration expecting none is refused by the action …
        try {
            $this->declare($family, 7, null);
            $this->fail('Expected HOUSEHOLD_DECLARATION_CHANGED.');
        } catch (HouseholdDeclarationException) {
            $this->addToAssertionCount(1);
        }

        // … and a writer bypassing it is refused by the partial unique index.
        try {
            $this->other()->table('family_household_declarations')->insert([
                'family_id' => $family->id, 'declared_household_size' => 7, 'source' => 'MANUAL_ENTRY',
                'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Expected the partial unique index to refuse a second current declaration.');
        } catch (QueryException $e) {
            $this->assertSame(self::UNIQUE_VIOLATION, (string) $e->getCode());
        }

        $this->assertSame([['declared_household_size' => 5, 'is_current' => true]], $this->rows($family));
    }
}
