<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\GazaLocationLevel;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * FB-GEO-01 Stage 3.2, Stage 3.1 audit T18: a residence location reference
 * is re-checked active inside the write transaction with FOR SHARE, so a
 * deactivation of the same location and the residence write serialize — a
 * value validated active can never be committed as a NEW reference to a
 * location deactivated meanwhile.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database); fixtures
 * are committed (DatabaseTruncation) so the second session sees them, as in
 * ResidenceUpdateConcurrencyTest. Synthetic data only.
 */
class ResidenceLocationConcurrencyTest extends TestCase
{
    use BuildsGazaWorkbooks, DatabaseTruncation;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private const LOCK_NOT_AVAILABLE = '55P03';

    private Family $family;

    private FamilyResidence $residence;

    private string $location;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);

        app(ImportGazaGeographyAction::class)->handle($this->geoSource($this->geoWorkbook()));
        $this->location = GazaLocation::idFor(GazaLocationLevel::CITY, '10');
        $this->family = Family::factory()->create();
        $this->residence = FamilyResidence::factory()->create(['family_id' => $this->family->id]);
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

    public function test_a_deactivation_waits_while_a_residence_write_holds_the_location(): void
    {
        $thrown = null;
        DB::beginTransaction();
        try {
            app(UpdateFamilyResidenceAction::class)->handle($this->family, ['current_location_id' => $this->location], null);

            // The residence transaction is still open: deactivating that location must wait.
            $this->other()->beginTransaction();
            $this->other()->statement("SET LOCAL lock_timeout = '300ms'");
            try {
                $this->other()->table('gaza_locations')->where('id', $this->location)->update(['is_active' => false]);
            } catch (QueryException $e) {
                $thrown = $e;
            } finally {
                $this->other()->rollBack();
            }
        } finally {
            DB::rollBack();
        }

        $this->assertInstanceOf(QueryException::class, $thrown, 'the deactivation should have waited');
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $thrown->errorInfo[0] ?? null);
        $this->assertTrue(GazaLocation::find($this->location)->is_active);
    }

    public function test_a_residence_write_waits_while_a_deactivation_is_pending_and_writes_nothing(): void
    {
        $this->other()->beginTransaction();
        $this->other()->table('gaza_locations')->where('id', $this->location)->update(['is_active' => false]);

        $thrown = null;
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            app(UpdateFamilyResidenceAction::class)->handle($this->family, ['current_location_id' => $this->location], null);
        } catch (QueryException $e) {
            $thrown = $e;
        } finally {
            DB::rollBack();
            $this->other()->rollBack();
        }

        $this->assertInstanceOf(QueryException::class, $thrown, 'the residence write should have waited on FOR SHARE');
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $thrown->errorInfo[0] ?? null);
        $this->assertNull($this->residence->fresh()->current_location_id);
    }
}
