<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaGeographyImportStatus;
use App\Enums\GazaLocationLevel;
use App\Exceptions\GazaGeographyImportException;
use App\Models\FamilyResidence;
use App\Models\GazaGeographyImportRun;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Support\Geography\GazaGeographySource;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FB-GEO-01 Stage 1C.1: persistent import history, lifecycle, failure
 * semantics and concurrency (ADR-FB-GEO-01 §4.4). SYNTHETIC workbooks.
 */
class GazaGeographyImportRunTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use RefreshDatabase;

    private function import(GazaGeographySource $source, bool $dryRun = false): array
    {
        return app(ImportGazaGeographyAction::class)->handle($source, $dryRun);
    }

    private function source(?string $path = null): GazaGeographySource
    {
        $path ??= $this->geoWorkbook();

        return new GazaGeographySource($path, (string) hash_file('sha256', $path), $this->syntheticFindings(), 'Synthetic test authority');
    }

    private function refusal(GazaGeographySource $source): GazaGeographyImportException
    {
        try {
            $this->import($source);
        } catch (GazaGeographyImportException $e) {
            return $e;
        }
        $this->fail('Expected the import to be refused.');
    }

    private function runningRow(): array
    {
        return [
            'source_authority' => 'Synthetic test authority', 'source_filename' => 'earlier.xlsx',
            'source_sha256' => str_repeat('b', 64), 'status' => GazaGeographyImportStatus::RUNNING, 'started_at' => Carbon::now(),
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml.');
        }
    }

    public function test_a_successful_import_is_recorded_with_provenance_and_counts(): void
    {
        $path = $this->geoWorkbook();
        $report = $this->import($this->source($path));

        $run = GazaGeographyImportRun::sole();
        $this->assertSame($run->id, $report['run_id']);
        $this->assertSame(GazaGeographyImportStatus::SUCCEEDED, $run->status);
        $this->assertSame('Synthetic test authority', $run->source_authority);
        $this->assertSame(basename($path), $run->source_filename, 'file name only, never a path');
        $this->assertSame(hash_file('sha256', $path), $run->source_sha256);
        $this->assertNull($run->source_version, 'a version is never invented');
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([2, 2, 2, 3, 5], [$run->governorates_count, $run->cities_count, $run->areas_count, $run->neighborhoods_count, $run->landmarks_count]);
        $this->assertSame([14, 0, 0, 8, 0], [$run->locations_created, $run->locations_unchanged, $run->locations_missing_from_source, $run->flags_created, $run->flags_unchanged]);
        $this->assertNull($run->error_summary);
    }

    public function test_the_official_source_records_mosd_authority_and_no_version(): void
    {
        $official = GazaGeographySource::official();

        $this->assertSame('Palestinian Ministry of Social Development (MoSD)', $official->authority);
        $this->assertNull($official->version);
    }

    public function test_a_repeated_import_is_a_separate_run_and_changes_no_geographic_data(): void
    {
        $source = $this->source();
        $this->import($source);
        GazaLocationReviewFlag::where('issue_code', 'POSSIBLE_DUPLICATE_LANDMARK')->update(['review_status' => 'DISMISSED', 'notes' => 'Two facilities.']);
        $locations = GazaLocation::orderBy('id')->get()->toArray();
        $flags = GazaLocationReviewFlag::orderBy('id')->get()->toArray();

        $report = $this->import($source);

        $this->assertSame(2, GazaGeographyImportRun::where('status', 'SUCCEEDED')->count());
        $second = GazaGeographyImportRun::find($report['run_id']);
        $this->assertSame([0, 14, 0, 0, 8], [$second->locations_created, $second->locations_unchanged, $second->locations_missing_from_source, $second->flags_created, $second->flags_unchanged]);
        $this->assertSame($locations, GazaLocation::orderBy('id')->get()->toArray());
        $this->assertSame($flags, GazaLocationReviewFlag::orderBy('id')->get()->toArray(), 'review statuses and notes preserved');
        $this->assertSame(2, GazaLocationReviewFlag::where('review_status', 'DISMISSED')->count());
    }

    public function test_a_dry_run_and_a_missing_file_are_not_recorded(): void
    {
        $report = $this->import($this->source(), dryRun: true);
        $this->assertNull($report['run_id']);

        $e = $this->refusal(new GazaGeographySource(sys_get_temp_dir().'/no-such-gaza-workbook.xlsx', str_repeat('0', 64)));
        $this->assertSame(GazaGeographyImportException::MISSING_FILE, $e->reason);
        $this->assertNull($e->runId);

        $this->assertSame(0, GazaGeographyImportRun::count());
        $this->assertSame(0, GazaLocation::count());
    }

    public function test_a_checksum_mismatch_is_recorded_as_rejected(): void
    {
        $path = $this->geoWorkbook();
        $e = $this->refusal(new GazaGeographySource($path, str_repeat('a', 64), [], 'Synthetic test authority'));

        $this->assertSame(GazaGeographyImportException::CHECKSUM_MISMATCH, $e->reason);
        $run = GazaGeographyImportRun::sole();
        $this->assertSame($run->id, $e->runId);
        $this->assertSame(GazaGeographyImportStatus::REJECTED, $run->status);
        $this->assertSame(hash_file('sha256', $path), $run->source_sha256, 'the checksum of the file actually supplied');
        $this->assertNull($run->governorates_count);
        $this->assertNotNull($run->finished_at);
        $this->assertStringStartsWith('[CHECKSUM_MISMATCH]', $run->error_summary);
        $this->assertSame(0, GazaLocation::count());
    }

    public function test_an_invalid_workbook_structure_is_recorded_as_rejected(): void
    {
        $header = GazaGeographySource::COLUMNS;
        $header[0] = 'GOVERNORATE';
        $e = $this->refusal($this->source($this->geoWorkbook(header: $header)));

        $this->assertSame(GazaGeographyImportException::INVALID_STRUCTURE, $e->reason);
        $run = GazaGeographyImportRun::sole();
        $this->assertSame(GazaGeographyImportStatus::REJECTED, $run->status);
        $this->assertStringContainsString('found: GOVERNORATE, GOV_TITLE', $run->error_summary);
        $this->assertSame(0, GazaLocation::count());
    }

    public function test_a_revision_refused_inside_the_transaction_is_recorded_as_rejected_and_writes_nothing(): void
    {
        $this->import($this->source());
        $before = GazaLocation::orderBy('id')->get()->toArray();

        $rows = $this->defaultGeoRows();
        $rows[0][1] = 'محافظة معدّلة';
        $rows[1][1] = 'محافظة معدّلة';
        $rows[2][1] = 'محافظة معدّلة';
        $e = $this->refusal($this->source($this->geoWorkbook($rows)));

        $this->assertSame(GazaGeographyImportException::REVISION_CONFLICT, $e->reason);
        $run = GazaGeographyImportRun::find($e->runId);
        $this->assertSame(GazaGeographyImportStatus::REJECTED, $run->status);
        $this->assertSame(5, $run->landmarks_count, 'source counts are kept for a run that started');
        $this->assertNull($run->locations_created);
        $this->assertStringContainsString('GOVERNORATE:1: name would change', $run->error_summary);
        $this->assertSame($before, GazaLocation::orderBy('id')->get()->toArray());
    }

    public function test_an_unexpected_failure_rolls_back_every_record_and_is_recorded_as_failed(): void
    {
        $residence = FamilyResidence::factory()->create();
        $residenceBefore = $residence->fresh()->toArray();
        // A pre-existing row already holds landmark 5's deterministic id, so
        // the INSERT fails after governorates … neighborhoods were written.
        DB::table('gaza_locations')->insert([
            'id' => GazaLocation::idFor(GazaLocationLevel::LANDMARK, '5'), 'level' => 'GOVERNORATE', 'source_code' => '999',
            'name' => 'سجل اصطناعي', 'is_active' => true, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        try {
            $this->import($this->source());
            $this->fail('Expected the import to fail.');
        } catch (QueryException $e) {
            // The original exception is rethrown unchanged.
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $e);
        }

        $this->assertSame(1, GazaLocation::count(), 'no partial import: only the pre-existing row');
        $this->assertSame(0, GazaLocationReviewFlag::count());
        $run = GazaGeographyImportRun::sole();
        $this->assertSame(GazaGeographyImportStatus::FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->locations_created);
        $this->assertStringStartsWith('Unexpected UniqueConstraintViolationException (SQLSTATE 23', $run->error_summary);
        foreach (['insert into', 'Host', '127.0.0.1', 'Database', 'password', 'gaza_locations'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $run->error_summary);
        }
        $this->assertSame($residenceBefore, $residence->fresh()->toArray());
    }

    public function test_the_database_allows_only_one_running_run(): void
    {
        GazaGeographyImportRun::create($this->runningRow());

        try {
            DB::transaction(fn () => GazaGeographyImportRun::create($this->runningRow()));
            $this->fail('Expected a second RUNNING run to be rejected.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, GazaGeographyImportRun::where('status', 'RUNNING')->count());
        }
    }

    public function test_a_concurrent_import_holding_the_postgresql_lock_is_rejected(): void
    {
        $this->requirePostgres();
        $default = config('database.default');
        config(['database.connections.gaza_lock_holder' => config("database.connections.{$default}")]);
        $holder = DB::connection('gaza_lock_holder');
        $holder->selectOne('SELECT pg_advisory_lock(?)', [ImportGazaGeographyAction::LOCK_KEY]);

        try {
            $e = $this->refusal($this->source());
        } finally {
            $holder->selectOne('SELECT pg_advisory_unlock(?)', [ImportGazaGeographyAction::LOCK_KEY]);
            DB::purge('gaza_lock_holder');
        }

        $this->assertSame(GazaGeographyImportException::CONCURRENT_IMPORT, $e->reason);
        $this->assertSame(GazaGeographyImportStatus::REJECTED, GazaGeographyImportRun::find($e->runId)->status);
        $this->assertSame(0, GazaLocation::count());

        // Once the other import has finished, the import runs normally.
        $this->import($this->source());
        $this->assertSame(14, GazaLocation::count());
    }

    public function test_on_postgresql_a_leftover_running_run_is_closed_as_interrupted(): void
    {
        $this->requirePostgres();
        $leftover = GazaGeographyImportRun::create($this->runningRow());

        $report = $this->import($this->source());

        $this->assertSame(GazaGeographyImportStatus::FAILED, $leftover->fresh()->status);
        $this->assertStringStartsWith('Interrupted', $leftover->fresh()->error_summary);
        $this->assertSame(GazaGeographyImportStatus::SUCCEEDED, GazaGeographyImportRun::find($report['run_id'])->status);
    }

    public function test_without_postgresql_a_running_run_blocks_the_import(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL closes a leftover run instead (advisory lock).');
        }
        $running = GazaGeographyImportRun::create($this->runningRow());

        $e = $this->refusal($this->source());

        $this->assertSame(GazaGeographyImportException::CONCURRENT_IMPORT, $e->reason);
        $this->assertSame(GazaGeographyImportStatus::RUNNING, $running->fresh()->status, 'the other run is not touched');
        $this->assertSame(0, GazaLocation::count());
    }

    public function test_the_import_run_migration_only_creates_its_own_table(): void
    {
        $migration = require database_path('migrations/2026_10_22_090002_create_gaza_geography_import_runs_table.php');
        $tables = fn () => collect(Schema::getTableListing(schemaQualified: false))->sort()->values()->all();
        $with = $tables();

        $migration->down();
        $without = $tables();
        $migration->up();

        $this->assertSame(['gaza_geography_import_runs'], array_values(array_diff($with, $without)));
        $this->assertSame($with, $tables());
    }
}
