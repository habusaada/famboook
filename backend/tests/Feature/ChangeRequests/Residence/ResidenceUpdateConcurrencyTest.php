<?php

namespace Tests\Feature\ChangeRequests\Residence;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\DisplacementStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyResidence;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
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
 * RESIDENCE_UPDATE under concurrency on PostgreSQL (PWA-6.1). The base
 * values are read with the current residence row locked, so approve and
 * apply WAIT for a parallel residence write (lock_not_available, 55P03)
 * instead of reading past it; once that write commits, the stale base is
 * detected and the newer data is never overwritten.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database); fixtures
 * are committed (DatabaseTruncation) so the second session sees them.
 * Synthetic data only.
 */
class ResidenceUpdateConcurrencyTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private FamilyAccessResult $context;

    private FamilyResidence $residence;

    private User $reviewer;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->residence = FamilyResidence::factory()->create([
            'family_id' => $this->context->family->id, 'neighborhood' => 'حي الأمل',
            'displacement_status' => DisplacementStatus::NOT_DISPLACED,
        ]);
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

    private function assertWaited(?Throwable $e): void
    {
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, (string) $e->getCode());
    }

    private function submit(string $neighborhood = 'حي النصر'): ChangeRequest
    {
        $residence = $this->residence->fresh();
        $input = [
            'governorate' => $residence->governorate, 'city' => $residence->city, 'area' => $residence->area,
            'neighborhood' => $neighborhood, 'address_text' => $residence->address_text,
            'original_residence_text' => $residence->original_residence_text,
            'displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => null,
        ];

        return app(SubmitChangeRequestAction::class)->handle($this->context, new ChangeRequestSubmission(
            ChangeRequestType::RESIDENCE_UPDATE, $input, null, (string) Str::uuid(),
        ))->request;
    }

    private function underReview(): ChangeRequest
    {
        $request = $this->submit();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        return $request;
    }

    public function test_approve_waits_for_a_parallel_residence_write_and_then_detects_the_stale_base(): void
    {
        $request = $this->underReview();

        $this->assertWaited($this->whileLocked('family_residences', $this->residence->id, fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer)));
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        $this->other()->table('family_residences')->where('id', $this->residence->id)->update(['neighborhood' => 'حي الزيتون']);
        try {
            app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
            $this->fail('A stale base was approved.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::BASE_CHANGED, $e->reason);
        }
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_apply_waits_for_a_parallel_residence_write_and_never_overwrites_it(): void
    {
        $request = $this->underReview();
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        // APPLY reports any unexpected failure — here the lock timeout of a wait — as a safe, retryable APPLY_FAILED.
        $waited = $this->whileLocked('family_residences', $this->residence->id, fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer));
        $this->assertInstanceOf(ChangeRequestException::class, $waited);
        $this->assertSame(ChangeRequestException::APPLY_FAILED, $waited->reason);
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
        $this->assertSame(S::APPROVED, $request->fresh()->status);

        $this->other()->table('family_residences')->where('id', $this->residence->id)->update(['neighborhood' => 'حي الزيتون']);
        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
            $this->fail('A stale request was applied.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::BASE_CHANGED, $e->reason);
        }
        $this->assertSame('حي الزيتون', $this->residence->fresh()->neighborhood);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->apply_failure_count);
    }

    public function test_a_second_submission_waits_for_the_family_lock_and_then_meets_the_open_request(): void
    {
        $this->assertWaited($this->whileLocked('families', $this->context->family->id, fn () => $this->submit()));
        $this->assertSame(0, ChangeRequest::count());

        $this->submit();
        try {
            $this->submit('حي الكرامة');
            $this->fail('A second open RESIDENCE_UPDATE was created.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::ALREADY_OPEN, $e->reason);
        }
        $this->assertSame(1, ChangeRequest::count());
    }
}
