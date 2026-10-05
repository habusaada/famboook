<?php

namespace Tests\Feature\People;

use App\Actions\ConfirmPersonAliveAction;
use App\Actions\RecordPersonDeathAction;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Exceptions\PersonLifeStatusException;
use App\Models\Person;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * FU-07: the PostgreSQL row lock that serializes ConfirmPersonAliveAction
 * with RecordPersonDeathAction on the same Person. A SECOND database
 * session holds the Person row the way a parallel death recording would;
 * the confirmation, run with a short lock_timeout, must WAIT for it
 * (lock_not_available, 55P03) instead of reading past it — and once the
 * death is committed, the re-read under lock refuses it.
 *
 * PostgreSQL ONLY, against the dedicated `_test` database of
 * phpunit.pgsql.xml; skipped before anything connects otherwise. Fixtures
 * are committed (DatabaseTruncation), because a second session cannot see
 * uncommitted rows. Synthetic data only.
 */
class LifeStatusConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

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

    private function confirm(Person $person): Person
    {
        return app(ConfirmPersonAliveAction::class)->handle($person, LifeStatusVerificationMethod::IN_PERSON, null);
    }

    /** Runs $operation while the other session holds the Person row FOR UPDATE; returns the SQLSTATE it ended with. */
    private function whilePersonLocked(Person $person, Closure $operation): ?string
    {
        $this->other()->beginTransaction();
        $this->other()->select('SELECT id FROM persons WHERE id = ? FOR UPDATE', [$person->id]);
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

    public function test_a_confirmation_waits_for_a_parallel_lock_on_the_person(): void
    {
        $person = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value]);

        $state = $this->whilePersonLocked($person, fn () => $this->confirm($person));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame(LifeStatus::UNKNOWN, $person->fresh()->life_status, 'The waiting confirmation changed nothing.');
        // Once released it runs, exactly once.
        $this->assertSame(LifeStatus::ALIVE, $this->confirm($person)->life_status);
    }

    public function test_a_death_committed_by_a_parallel_session_wins_and_the_confirmation_is_refused(): void
    {
        $person = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value]);
        // The parallel session records the death and commits first.
        $this->other()->transaction(fn () => $this->other()->table('persons')->where('id', $person->id)->lockForUpdate()->update([
            'life_status' => LifeStatus::DECEASED->value,
        ]));

        try {
            $this->confirm($person);
            $this->fail('Expected PERSON_DECEASED.');
        } catch (PersonLifeStatusException $e) {
            $this->assertSame(PersonLifeStatusException::PERSON_DECEASED, $e->reason);
        }
        $this->assertSame(LifeStatus::DECEASED, $person->fresh()->life_status);
    }

    // FU-10: the death recording takes the same lock.

    private function recordDeath(Person $person): Person
    {
        return app(RecordPersonDeathAction::class)->handle($person, null, LifeStatusVerificationMethod::IN_PERSON, null);
    }

    public function test_a_death_waits_for_a_parallel_lock_on_the_person(): void
    {
        $person = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value]);

        $state = $this->whilePersonLocked($person, fn () => $this->recordDeath($person));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame(LifeStatus::UNKNOWN, $person->fresh()->life_status, 'The waiting death recording changed nothing.');
        $this->assertSame(LifeStatus::DECEASED, $this->recordDeath($person)->life_status);
    }

    public function test_a_confirmation_committed_first_is_followed_by_a_valid_death(): void
    {
        $person = Person::factory()->create(['life_status' => LifeStatus::UNKNOWN->value]);
        // The parallel session confirms the Person alive and commits first.
        $this->other()->transaction(fn () => $this->other()->table('persons')->where('id', $person->id)->lockForUpdate()->update([
            'life_status' => LifeStatus::ALIVE->value,
        ]));

        $this->assertSame(LifeStatus::DECEASED, $this->recordDeath($person)->life_status);
    }

    public function test_a_death_committed_by_a_parallel_session_refuses_a_second_death(): void
    {
        $person = Person::factory()->create(['life_status' => LifeStatus::ALIVE->value]);
        $this->other()->transaction(fn () => $this->other()->table('persons')->where('id', $person->id)->lockForUpdate()->update([
            'life_status' => LifeStatus::DECEASED->value, 'death_date' => '2024-01-01',
        ]));

        try {
            $this->recordDeath($person);
            $this->fail('Expected PERSON_ALREADY_DECEASED.');
        } catch (PersonLifeStatusException $e) {
            $this->assertSame(PersonLifeStatusException::PERSON_ALREADY_DECEASED, $e->reason);
        }
        $this->assertSame('2024-01-01', $person->fresh()->death_date->toDateString());
    }
}
