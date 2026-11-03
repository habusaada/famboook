<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryAdmissionUnavailableException as Unavailable;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionFailure as Failure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2a-F: the fail-closed contract the future admission action
 * must use. No action, controller or route exists; these tests drive the contract
 * directly. Synthetic values only.
 */
class RegistryAdmissionFailureTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const CHILD = '401234567';

    private const TOKEN = 'eyJpdiI6InN5bnRoZXRpYy10b2tlbiJ9';

    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $logged = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
    }

    /** Exactly one error line may be written, and it is captured; any other log call fails the test. */
    private function captureLog(): void
    {
        $this->logged = null;
        Log::shouldReceive('error')->once()->andReturnUsing(function (string $message, array $context) {
            $this->logged = [$message, $context];
        });
    }

    private function queryException(string $sqlstate): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlstate}]: driver detail about ".self::CHILD);
        $pdo->errorInfo = [$sqlstate, 7, 'driver detail about '.self::CHILD];

        return new QueryException('pgsql', 'insert into persons (national_id) values (?)', [self::CHILD], $pdo);
    }

    private function failure(callable $work): Unavailable
    {
        try {
            Failure::run($work);
            $this->fail('Nothing failed.');
        } catch (Unavailable $e) {
            return $e;
        }
    }

    private function assertSanitized(Unavailable $e, string $code, ?string $sqlstate): void
    {
        [$message, $context] = $this->logged;
        $this->assertSame('Registry admission failed closed.', $message);
        $this->assertSame(['correlation_id', 'failure', 'exception', 'sqlstate'], array_keys($context));
        $this->assertSame([$e->correlationId, $code, $sqlstate], [$context['correlation_id'], $context['failure'], $context['sqlstate']]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $e->correlationId);

        $everything = json_encode($this->logged).$e->getMessage().json_encode($e->render()->getData(true));
        foreach ([self::CHILD, self::TOKEN, 'insert into', 'persons', 'driver detail', 'SQLSTATE[', 'family_registry'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, $secret);
        }
        $this->assertNull($e->getPrevious(), 'the original exception is never chained');
    }

    public function test_a_database_failure_rolls_back_and_answers_503_without_sql_or_bound_values(): void
    {
        $actor = User::factory()->familySide()->create();
        $family = FamilyMembership::factory()->householdHead()->create()->family;
        $this->captureLog();

        $e = $this->failure(fn () => DB::transaction(function () use ($actor, $family) {
            Audit::idempotencyConflict($actor, $family, null);
            DB::insert('insert into a_table_that_does_not_exist (national_id, token) values (?, ?)', [self::CHILD, self::TOKEN]);
        }));

        $this->assertSame(0, DB::table('family_registry_admission_events')->count(), 'the transaction rolled back');
        $this->assertSanitized($e, Failure::DATABASE_ERROR, DB::getDriverName() === 'pgsql' ? '42P01' : 'HY000');
        $this->assertFalse($e->retryable);

        $response = $e->render();
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['message', 'code', 'correlation_id'], array_keys($response->getData(true)));
        $this->assertSame(['REGISTRY_UNAVAILABLE', $e->correlationId], [$response->getData(true)['code'], $response->getData(true)['correlation_id']]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Retry-After'));
    }

    public function test_lock_timeouts_deadlocks_and_serialization_failures_are_retryable(): void
    {
        foreach (['55P03' => Failure::LOCK_TIMEOUT, '40P01' => Failure::DEADLOCK, '40001' => Failure::SERIALIZATION] as $state => $code) {
            $this->captureLog();
            $e = $this->failure(fn () => throw $this->queryException($state));
            $this->assertSanitized($e, $code, $state);
            $this->assertTrue($e->retryable, $state);
            $this->assertSame('5', $e->render()->headers->get('Retry-After'), $state);
        }
        $this->captureLog();
        $e = $this->failure(fn () => throw $this->queryException('23505'));
        $this->assertSanitized($e, Failure::DATABASE_ERROR, '23505');
        $this->assertFalse($e->retryable);
    }

    public function test_any_other_failure_is_internal_and_its_message_is_never_logged(): void
    {
        $this->captureLog();
        $e = $this->failure(fn () => throw new RuntimeException('leaked '.self::CHILD.' '.self::TOKEN));
        $this->assertSanitized($e, Failure::INTERNAL_ERROR, null);
        $this->assertSame(RuntimeException::class, $this->logged[1]['exception']);

        // A malformed SQLSTATE is not logged as one.
        $this->captureLog();
        $pdo = new PDOException('x');
        $pdo->errorInfo = ['<script>', 1, self::CHILD];
        $e = $this->failure(fn () => throw $pdo);
        $this->assertSanitized($e, Failure::DATABASE_ERROR, null);
    }

    public function test_refusals_pass_through_unchanged_and_nothing_is_logged(): void
    {
        Log::shouldReceive('error')->never();

        foreach ([new RefException(RefException::REFERENCE_INVALID), new Unavailable('00000000-0000-4000-8000-000000000000', false)] as $refusal) {
            try {
                Failure::run(fn () => throw $refusal);
                $this->fail('Swallowed.');
            } catch (RefException|Unavailable $e) {
                $this->assertSame($refusal, $e);
            }
        }
        try {
            Failure::run(fn () => throw new InvalidArgumentException('an answer'), InvalidArgumentException::class);
            $this->fail('Swallowed.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('an answer', $e->getMessage());
        }
        $this->assertSame(42, Failure::run(fn () => 42));
    }

    public function test_the_default_handler_never_logs_it_and_renders_the_sanitized_503(): void
    {
        Log::shouldReceive('error', 'critical', 'warning', 'info', 'debug', 'log', 'channel', 'stack')->never();
        $e = new Unavailable('00000000-0000-4000-8000-000000000000', true);

        app(ExceptionHandler::class)->report($e);
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/family/household/registry-children/admissions', 'POST'), $e);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['message' => Unavailable::MESSAGE, 'code' => 'REGISTRY_UNAVAILABLE', 'correlation_id' => '00000000-0000-4000-8000-000000000000'],
            json_decode((string) $response->getContent(), true));
        $this->assertSame('5', $response->headers->get('Retry-After'));
    }

    public function test_a_real_postgresql_lock_timeout_rolls_back_and_is_retryable(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A real lock timeout needs PostgreSQL.');
        }
        $actor = User::factory()->familySide()->create();
        $family = FamilyMembership::factory()->householdHead()->create()->family;
        config(['database.connections.pgsql_failure_probe' => config('database.connections.pgsql')]);
        $other = DB::connection('pgsql_failure_probe');
        $key = 'famboook.test.registry-admission-failure';
        $other->select('SELECT pg_advisory_lock(hashtext(?))', [$key]);

        try {
            $this->captureLog();
            $e = $this->failure(fn () => DB::transaction(function () use ($actor, $family, $key) {
                Audit::registryUnavailable($actor, $family, 3, null);
                DB::statement("SET LOCAL lock_timeout = '150ms'");
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
            }));
        } finally {
            $other->select('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            DB::disconnect('pgsql_failure_probe');
        }

        $this->assertSanitized($e, Failure::LOCK_TIMEOUT, '55P03');
        $this->assertTrue($e->retryable);
        $this->assertSame(0, DB::table('family_registry_admission_events')->count(), 'the transaction rolled back');
        // The test transaction is usable again: the savepoint was rolled back, not the session poisoned.
        $this->assertSame(1, (int) DB::scalar('SELECT 1'));
    }
}
