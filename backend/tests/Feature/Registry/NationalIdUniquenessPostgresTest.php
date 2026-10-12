<?php

namespace Tests\Feature\Registry;

use App\Actions\AddFamilyMemberAction;
use App\Actions\CorrectNationalIdAction;
use App\Actions\CreatePersonAction;
use App\Exceptions\DuplicateNationalIdException;
use App\Models\Family;
use App\Models\Person;
use Database\Seeders\RelationshipTypeSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * CO-01D.4 (docs/04 DB-ADR-061): National ID uniqueness enforced by
 * PostgreSQL — NOT NULL, chk_person_national_id_format and
 * uq_persons_national_id over every row, soft-deleted included — plus the
 * application's mapping of a lost race, real concurrency through a second
 * session and separate PHP processes, and the migration's pre-flight and
 * rollback.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Data is
 * committed (truncation, not transactions) so separate sessions see it.
 * Synthetic National IDs only.
 */
class NationalIdUniquenessPostgresTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const ACTOR = __DIR__.'/../../Support/ChangeRequests/concurrent-actor.php';

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_10_20_090000_enforce_unique_person_national_id.php';

    private const NID = '401234567';

    // SQLSTATEs.
    private const UNIQUE = '23505';

    private const CHECK = '23514';

    private const NOT_NULL = '23502';

    private const LOCK_TIMEOUT = '55P03';

    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->seed(RelationshipTypeSeeder::class);
        // The actor processes connect to the SAME isolated test database.
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'), 'DB_URL' => '', 'APP_ENV' => 'testing'] as $key => $value) {
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
            DB::connection('other_runner')->disconnect();
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

    // ---------------------------------------------------------------- helpers

    /** A raw INSERT that bypasses every application check; returns the SQLSTATE or null. */
    private function rawInsert(?string $nationalId, ?Connection $on = null): ?string
    {
        try {
            ($on ?? DB::connection())->table('persons')->insert([
                'person_code' => 'PER-T'.random_int(100000, 999999), 'full_name' => 'شخص تجريبي', 'national_id' => $nationalId,
                'life_status' => 'ALIVE', 'marital_status' => 'UNKNOWN', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function createPerson(string $nationalId): Person
    {
        return app(CreatePersonAction::class)->handle(['full_name' => 'شخص تجريبي', 'national_id' => $nationalId, 'gender' => 'MALE', 'life_status' => 'ALIVE'], null);
    }

    private function constraintExists(string $name): bool
    {
        return DB::scalar('SELECT count(*) FROM pg_constraint WHERE conname = ?', [$name]) > 0;
    }

    private function indexExists(string $name): bool
    {
        return DB::scalar('SELECT count(*) FROM pg_indexes WHERE tablename = ? AND indexname = ?', ['persons', $name]) > 0;
    }

    private function nullable(): bool
    {
        return DB::scalar("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'persons' AND column_name = 'national_id'") === 'YES';
    }

    private function migration(): object
    {
        return require self::MIGRATION;
    }

    /** Runs the migration's up() as Laravel does on PostgreSQL: inside one transaction. */
    private function migrateUp(): void
    {
        DB::transaction(fn () => $this->migration()->up());
    }

    private function spawn(string ...$args): int
    {
        $process = proc_open([PHP_BINARY, self::ACTOR, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        $this->children[] = ['process' => $process, 'pipes' => $pipes];
        $ready = trim((string) fgets($pipes[1]));
        if ($ready !== 'READY') {
            $this->fail("actor did not start ({$ready}): ".stream_get_contents($pipes[2]));
        }

        return count($this->children) - 1;
    }

    private function go(int $child, int $milliseconds): void
    {
        fwrite($this->children[$child]['pipes'][0], $milliseconds.PHP_EOL);
        fflush($this->children[$child]['pipes'][0]);
    }

    private function outcomeOf(int $child): string
    {
        return trim((string) fgets($this->children[$child]['pipes'][1]));
    }

    // ----------------------------------------------------------------- schema

    public function test_the_schema_enforces_not_null_format_and_uniqueness(): void
    {
        $this->assertFalse($this->nullable());
        $this->assertTrue($this->constraintExists('chk_person_national_id_format'));
        $this->assertTrue($this->constraintExists('uq_persons_national_id'));
        $this->assertFalse($this->indexExists('persons_national_id_index'));
    }

    public function test_exact_duplicates_are_refused_by_the_database(): void
    {
        $this->assertNull($this->rawInsert(self::NID));
        $this->assertSame(self::UNIQUE, $this->rawInsert(self::NID));
        $this->assertSame(1, Person::withTrashed()->where('national_id', self::NID)->count());
    }

    public function test_equivalent_formatted_values_cannot_be_stored_so_cannot_bypass_uniqueness(): void
    {
        $this->assertNull($this->rawInsert(self::NID));
        foreach (['401 234 567', '401-234-567', '٤٠١٢٣٤٥٦٧', ' 401234567', '401234567 ', "401234567\u{200F}"] as $variant) {
            $this->assertSame(self::CHECK, $this->rawInsert($variant), 'stored: '.bin2hex($variant));
        }
        $this->assertSame(1, Person::withTrashed()->count());
    }

    public function test_missing_invalid_and_placeholder_values_are_refused_by_the_database(): void
    {
        $this->assertSame(self::NOT_NULL, $this->rawInsert(null));
        foreach (['', '40123456', '4012345678', 'A40123456', '000000000', '999999999'] as $invalid) {
            $this->assertSame(self::CHECK, $this->rawInsert($invalid), 'stored: '.$invalid);
        }
        $this->assertSame(0, Person::withTrashed()->count());
    }

    public function test_an_update_cannot_create_a_duplicate_or_an_invalid_value(): void
    {
        $this->rawInsert(self::NID);
        $this->rawInsert('402345678');
        $other = Person::where('national_id', '402345678')->sole();

        foreach ([[self::NID, self::UNIQUE], [null, self::NOT_NULL], ['402 345 678', self::CHECK]] as [$value, $state]) {
            try {
                DB::table('persons')->where('id', $other->id)->update(['national_id' => $value]);
                $this->fail('Expected '.$state);
            } catch (QueryException $e) {
                $this->assertSame($state, (string) $e->getCode());
            }
        }
        $this->assertSame('402345678', $other->fresh()->national_id);
    }

    // ----------------------------------------------------------- soft deletes

    public function test_a_soft_deleted_person_keeps_their_national_id(): void
    {
        $deleted = $this->createPerson(self::NID);
        $deleted->delete();

        // The database refuses a raw insert …
        $this->assertSame(self::UNIQUE, $this->rawInsert(self::NID));

        // … and the application refuses up front, without describing the deleted record.
        try {
            $this->createPerson(self::NID);
            $this->fail('Expected a duplicate.');
        } catch (DuplicateNationalIdException $e) {
            $this->assertCount(0, $e->matches);
        }
        $this->assertSame(1, Person::withTrashed()->where('national_id', self::NID)->count());
        $this->assertTrue(Person::withTrashed()->find($deleted->id)->trashed());
    }

    // ------------------------------------------------------- application layer

    public function test_new_persons_are_stored_canonically_and_equivalents_are_duplicates(): void
    {
        $person = $this->createPerson('٤٠١-٢٣٤ ٥٦٧');
        $this->assertSame(self::NID, $person->fresh()->national_id);

        foreach (['401 234 567', '401.234/567', '۴۰۱۲۳۴۵۶۷'] as $equivalent) {
            try {
                $this->createPerson($equivalent);
                $this->fail('Expected a duplicate for an equivalent value.');
            } catch (DuplicateNationalIdException) {
                // Refused.
            }
        }
        $this->assertSame(1, Person::withTrashed()->count());
    }

    public function test_a_correction_is_stored_canonically_and_cannot_take_a_held_value(): void
    {
        $this->createPerson(self::NID);
        $other = $this->createPerson('402345678');

        $this->expectException(DuplicateNationalIdException::class);
        try {
            app(CorrectNationalIdAction::class)->handle($other, '401 234 567', null);
        } finally {
            $this->assertSame('402345678', $other->fresh()->national_id);
            $corrected = app(CorrectNationalIdAction::class)->handle($other, '٤٠٣٤٥٦٧٨٩', null);
            $this->assertSame('403456789', $corrected->fresh()->national_id);
        }
    }

    // ------------------------------------------------------------ concurrency

    public function test_concurrent_raw_inserts_wait_on_the_unique_index_and_never_both_commit(): void
    {
        $other = DB::connection('other_runner');
        $other->beginTransaction();
        $this->assertNull($this->rawInsert(self::NID, $other));

        // While the other session's insert is uncommitted, ours waits on the index.
        DB::statement("SET lock_timeout = '300ms'");
        $this->assertSame(self::LOCK_TIMEOUT, $this->rawInsert(self::NID));

        $other->commit();
        $this->assertSame(self::UNIQUE, $this->rawInsert(self::NID));
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
    }

    public function test_concurrent_raw_updates_to_the_same_value_never_both_commit(): void
    {
        $this->rawInsert('402345678');
        $this->rawInsert('403456789');
        [$a, $b] = Person::orderBy('id')->pluck('id')->all();

        $other = DB::connection('other_runner');
        $other->beginTransaction();
        $other->table('persons')->where('id', $a)->update(['national_id' => self::NID]);

        DB::statement("SET lock_timeout = '300ms'");
        try {
            DB::table('persons')->where('id', $b)->update(['national_id' => self::NID]);
            $this->fail('Expected to wait on the index.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_TIMEOUT, (string) $e->getCode());
        }

        $other->commit();
        try {
            DB::table('persons')->where('id', $b)->update(['national_id' => self::NID]);
            $this->fail('Expected a unique violation.');
        } catch (QueryException $e) {
            $this->assertSame(self::UNIQUE, (string) $e->getCode());
        }
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
    }

    public function test_two_concurrent_creations_in_separate_processes_produce_exactly_one_person(): void
    {
        $family = Family::factory()->create();
        $first = $this->spawn('add-member', (string) $family->id, self::NID);
        $second = $this->spawn('add-member', (string) $family->id, '401 234 567');
        $this->go($first, 0);
        $this->go($second, 0);

        $outcomes = [$this->outcomeOf($first), $this->outcomeOf($second)];
        sort($outcomes);

        $this->assertSame('FAIL '.DuplicateNationalIdException::class, $outcomes[0]);
        $this->assertSame('OK', $outcomes[1]);
        $this->assertSame(1, Person::withTrashed()->where('national_id', self::NID)->count());
    }

    public function test_two_concurrent_corrections_to_the_same_value_produce_exactly_one_holder(): void
    {
        $a = $this->createPerson('402345678');
        $b = $this->createPerson('403456789');
        $first = $this->spawn('correct', (string) $a->id, self::NID);
        $second = $this->spawn('correct', (string) $b->id, self::NID);
        $this->go($first, 0);
        $this->go($second, 0);

        $outcomes = [$this->outcomeOf($first), $this->outcomeOf($second)];
        sort($outcomes);

        $this->assertSame('FAIL '.DuplicateNationalIdException::class, $outcomes[0]);
        $this->assertSame('OK', $outcomes[1]);
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
    }

    public function test_a_lost_race_against_a_writer_that_bypasses_the_guard_is_a_generic_duplicate(): void
    {
        // Another session has inserted the value but not committed: the
        // application guard cannot see it, so only the unique index decides.
        $holder = $this->spawn('hold-insert', self::NID);
        $this->go($holder, 500);

        try {
            app(AddFamilyMemberAction::class)->handle(Family::factory()->create(), [
                'full_name' => 'شخص تجريبي', 'national_id' => self::NID, 'gender' => 'MALE',
                'relationship_type_id' => DB::table('relationship_types')->where('code', 'SON')->value('id'),
            ], null);
            $this->fail('Expected a duplicate.');
        } catch (DuplicateNationalIdException $e) {
            // No reference to the holder, and no driver message carrying the value.
            $this->assertCount(0, $e->matches);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::NID, $e->getMessage());
        }

        $this->assertSame('OK', $this->outcomeOf($holder));
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertSame(0, DB::table('family_memberships')->count());
    }

    // -------------------------------------------------------------- migration

    public function test_the_migration_rolls_back_and_reapplies_without_touching_persons(): void
    {
        $this->createPerson(self::NID);
        $this->createPerson('402345678')->delete();
        $before = DB::table('persons')->orderBy('id')->get()->toJson();

        $this->migration()->down();
        $this->assertTrue($this->nullable());
        $this->assertFalse($this->constraintExists('chk_person_national_id_format'));
        $this->assertFalse($this->constraintExists('uq_persons_national_id'));
        $this->assertTrue($this->indexExists('persons_national_id_index'));
        $this->assertSame($before, DB::table('persons')->orderBy('id')->get()->toJson());

        $this->migrateUp();
        $this->assertFalse($this->nullable());
        $this->assertTrue($this->constraintExists('chk_person_national_id_format'));
        $this->assertTrue($this->constraintExists('uq_persons_national_id'));
        $this->assertFalse($this->indexExists('persons_national_id_index'));
        $this->assertSame($before, DB::table('persons')->orderBy('id')->get()->toJson());
    }

    public function test_the_migration_fails_fast_behind_a_long_transaction_and_changes_nothing(): void
    {
        $this->createPerson(self::NID);
        $this->migration()->down();

        // Another session holds a row lock on persons (a long transaction).
        $other = DB::connection('other_runner');
        $other->beginTransaction();
        $other->select('SELECT id FROM persons FOR UPDATE');

        $started = microtime(true);
        try {
            $this->migrateUp();
            $this->fail('Expected the migration to give up on the lock.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_TIMEOUT, (string) $e->getCode());
        }
        // lock_timeout 5s: it gives up instead of queueing indefinitely.
        $this->assertLessThan(15, microtime(true) - $started);
        $this->assertTrue($this->nullable());
        $this->assertFalse($this->constraintExists('uq_persons_national_id'));

        $other->rollBack();
        $this->migrateUp();
        $this->assertTrue($this->constraintExists('uq_persons_national_id'));
    }

    public function test_the_migration_refuses_legacy_problems_with_counts_only_and_changes_nothing(): void
    {
        $this->migration()->down();
        // Legacy shapes the constraints forbid: missing, non-canonical,
        // placeholder, and a duplicate pair (one member soft-deleted).
        $this->rawInsert(null);
        $this->rawInsert('٤٠٢٣٤٥٦٧٨');
        $this->rawInsert('000000000');
        $this->rawInsert(self::NID);
        $this->rawInsert(self::NID);
        DB::table('persons')->where('id', DB::table('persons')->where('national_id', self::NID)->min('id'))->update(['deleted_at' => now()]);
        $before = DB::table('persons')->orderBy('id')->get()->toJson();

        try {
            $this->migrateUp();
            $this->fail('Expected the pre-flight to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 missing, 2 not stored as nine digits (or placeholder), 1 duplicate group(s)', $e->getMessage());
            $this->assertStringContainsString('famboook:national-id-audit', $e->getMessage());
            foreach ([self::NID, '٤٠٢٣٤٥٦٧٨', '000000000'] as $value) {
                $this->assertStringNotContainsString($value, $e->getMessage());
            }
        }

        $this->assertSame($before, DB::table('persons')->orderBy('id')->get()->toJson());
        $this->assertTrue($this->nullable());
        $this->assertFalse($this->constraintExists('uq_persons_national_id'));

        // Restore the schema for the following tests (test data only).
        DB::table('persons')->delete();
        $this->migrateUp();
        $this->assertTrue($this->constraintExists('uq_persons_national_id'));
    }
}
