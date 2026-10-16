<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Enums\GazaLocationReviewSeverity;
use App\Enums\GazaLocationReviewStatus;
use App\Exceptions\GazaGeographyImportException;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Support\Geography\GazaGeographySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FB-GEO-01 Stage 1C import (ADR-FB-GEO-01 §3.3, Stage 1B register) on
 * SYNTHETIC workbooks.
 */
class GazaGeographyImportTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use RefreshDatabase;

    private function import(GazaGeographySource $source, bool $dryRun = false): array
    {
        return app(ImportGazaGeographyAction::class)->handle($source, $dryRun);
    }

    private function location(GazaLocationLevel $level, string $code): GazaLocation
    {
        return GazaLocation::query()->where('level', $level->value)->where('source_code', $code)->firstOrFail();
    }

    /** @return GazaGeographyImportException the refusal, after checking nothing was written */
    private function refused(GazaGeographySource $source, string $reason): GazaGeographyImportException
    {
        $before = [GazaLocation::count(), GazaLocationReviewFlag::count()];
        try {
            $this->import($source);
        } catch (GazaGeographyImportException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($before, [GazaLocation::count(), GazaLocationReviewFlag::count()], 'a refused import writes nothing');

            return $e;
        }
        $this->fail("Expected the import to be refused with {$reason}.");
    }

    public function test_it_imports_every_level_parent_first_with_the_source_values(): void
    {
        $report = $this->import($this->geoSource($this->geoWorkbook()));

        $expected = ['GOVERNORATE' => 2, 'CITY' => 2, 'AREA' => 2, 'NEIGHBORHOOD' => 3, 'LANDMARK' => 5];
        $this->assertSame($expected, $report['source']);
        $this->assertSame($expected, $report['created']);
        $this->assertSame(14, GazaLocation::count());
        $this->assertSame(0, GazaLocation::where('is_active', false)->count());

        // Deterministic ids and parent links by level.
        $landmark = $this->location(GazaLocationLevel::LANDMARK, '5');
        $this->assertSame(GazaLocation::idFor(GazaLocationLevel::LANDMARK, '5'), $landmark->id);
        $chain = [];
        for ($node = $landmark; $node !== null; $node = $node->parent) {
            $chain[] = $node->level->value.':'.$node->source_code;
            $this->assertSame($node->parent?->level, $node->parent_level);
        }
        $this->assertSame(['LANDMARK:5', 'NEIGHBORHOOD:102', 'AREA:20', 'CITY:20', 'GOVERNORATE:2'], $chain);

        // The same code at two levels is two records.
        $this->assertNotSame($this->location(GazaLocationLevel::CITY, '10')->id, $this->location(GazaLocationLevel::AREA, '10')->id);

        // Source text exactly as supplied: no trimming, no Arabic normalization.
        $this->assertSame('حي رقم 7 ', $this->location(GazaLocationLevel::NEIGHBORHOOD, '100')->name);
        $this->assertSame('حي رقم 7', $this->location(GazaLocationLevel::NEIGHBORHOOD, '100')->displayName());
        $this->assertSame('محافظة الاختبار', $this->location(GazaLocationLevel::GOVERNORATE, '1')->name);
        $this->assertSame('النور ', $this->location(GazaLocationLevel::LANDMARK, '3')->name);

        // Landmark type kept as supplied — including the unusual one.
        $unusual = $this->location(GazaLocationLevel::LANDMARK, '4');
        $this->assertSame(['8', 'نوع غير مألوف'], [$unusual->landmark_type_code, $unusual->landmark_type_name]);
        $this->assertNull($this->location(GazaLocationLevel::NEIGHBORHOOD, '102')->landmark_type_code);
    }

    public function test_review_flags_are_recorded_without_changing_any_location(): void
    {
        $report = $this->import($this->geoSource($this->geoWorkbook()));

        $this->assertSame([
            'PARENT_PLACE_MISMATCH' => 1,
            'UNUSUAL_LANDMARK_TYPE' => 1,
            'SAME_PARENT_SIMILAR_NAME' => 2,
            'POSSIBLE_DUPLICATE_LANDMARK' => 2,
            'NAME_WHITESPACE' => 2,
        ], $report['flags_created']);

        $flagged = fn (GazaLocationReviewIssue $issue) => GazaLocationReviewFlag::where('issue_code', $issue->value)
            ->with('location')->get()->map(fn ($f) => $f->location->level->value.':'.$f->location->source_code)->sort()->values()->all();
        $this->assertSame(['NEIGHBORHOOD:100', 'NEIGHBORHOOD:101'], $flagged(GazaLocationReviewIssue::SAME_PARENT_SIMILAR_NAME));
        $this->assertSame(['LANDMARK:1', 'LANDMARK:3'], $flagged(GazaLocationReviewIssue::POSSIBLE_DUPLICATE_LANDMARK));
        $this->assertSame(['LANDMARK:3', 'NEIGHBORHOOD:100'], $flagged(GazaLocationReviewIssue::NAME_WHITESPACE));
        $this->assertSame(['LANDMARK:4'], $flagged(GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE));
        $this->assertSame(['NEIGHBORHOOD:102'], $flagged(GazaLocationReviewIssue::PARENT_PLACE_MISMATCH));

        // Landmark 2 has the same name as 1 but in another neighborhood: not a duplicate.
        $this->assertSame(0, $this->location(GazaLocationLevel::LANDMARK, '2')->reviewFlags()->count());

        $flag = GazaLocationReviewFlag::where('issue_code', 'UNUSUAL_LANDMARK_TYPE')->sole();
        $this->assertSame(GazaLocationReviewSeverity::WARNING, $flag->severity);
        $this->assertSame(GazaLocationReviewStatus::OPEN, $flag->review_status);
        $this->assertSame('FB-GEO-01 Stage 1A W2 (§6.2)', $flag->source_reference);
        $this->assertStringContainsString('Source rows: 5.', $flag->notes);
        $this->assertSame(GazaLocationReviewSeverity::INFO, GazaLocationReviewFlag::where('issue_code', 'NAME_WHITESPACE')->first()->severity);

        // Flagged records stay as supplied, active and selectable (Stage 1B Q1).
        foreach (GazaLocationReviewFlag::with('location')->get() as $f) {
            $this->assertTrue($f->location->is_active);
        }
        $this->assertSame(14, GazaLocation::count());
    }

    public function test_reimporting_the_same_source_changes_nothing(): void
    {
        $source = $this->geoSource($this->geoWorkbook());
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->import($source);

        // Reviewer and administrator decisions made after the first import.
        GazaLocationReviewFlag::where('issue_code', 'UNUSUAL_LANDMARK_TYPE')->update(['review_status' => 'CONFIRMED', 'notes' => 'Reviewed.']);
        $this->location(GazaLocationLevel::LANDMARK, '5')->update(['is_active' => false]);
        $snapshot = GazaLocation::orderBy('id')->get()->toArray();
        $flags = GazaLocationReviewFlag::orderBy('id')->get()->toArray();

        Carbon::setTestNow('2026-10-16 10:00:00');
        $report = $this->import($source);

        $this->assertSame(0, array_sum($report['created']));
        $this->assertSame(14, array_sum($report['unchanged']));
        $this->assertSame(0, array_sum($report['flags_created']));
        $this->assertSame(8, array_sum($report['flags_existing']));
        $this->assertSame([], $report['missing_from_source']);
        $this->assertSame($snapshot, GazaLocation::orderBy('id')->get()->toArray(), 'never re-stamped or reactivated');
        $this->assertSame($flags, GazaLocationReviewFlag::orderBy('id')->get()->toArray(), 'review status never reset');
        Carbon::setTestNow();
    }

    public function test_a_dry_run_reports_but_writes_nothing(): void
    {
        $report = $this->import($this->geoSource($this->geoWorkbook()), dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(14, array_sum($report['created']));
        $this->assertSame(8, array_sum($report['flags_created']));
        $this->assertSame(0, GazaLocation::count());
        $this->assertSame(0, GazaLocationReviewFlag::count());
    }

    public function test_a_missing_source_file_is_refused(): void
    {
        $this->refused(new GazaGeographySource(sys_get_temp_dir().'/no-such-gaza-workbook.xlsx', str_repeat('0', 64)), GazaGeographyImportException::MISSING_FILE);
    }

    public function test_a_checksum_mismatch_is_refused(): void
    {
        $path = $this->geoWorkbook();
        $e = $this->refused(new GazaGeographySource($path, str_repeat('a', 64)), GazaGeographyImportException::CHECKSUM_MISMATCH);
        $this->assertStringContainsString(hash_file('sha256', $path), implode(' ', $e->problems));
    }

    public function test_unexpected_columns_are_refused(): void
    {
        $renamed = GazaGeographySource::COLUMNS;
        $renamed[12] = 'LAN_TITLE_V2';
        $this->refused($this->geoSource($this->geoWorkbook(header: $renamed)), GazaGeographyImportException::INVALID_STRUCTURE);

        $extra = [...GazaGeographySource::COLUMNS, 'NOTES'];
        $this->refused($this->geoSource($this->geoWorkbook(header: $extra)), GazaGeographyImportException::INVALID_STRUCTURE);

        $missing = array_slice(GazaGeographySource::COLUMNS, 0, 12);
        $this->refused($this->geoSource($this->geoWorkbook(header: $missing)), GazaGeographyImportException::INVALID_STRUCTURE);
    }

    public function test_an_unexpected_worksheet_is_refused(): void
    {
        $this->refused($this->geoSource($this->geoWorkbook(sheet: 'Sheet1')), GazaGeographyImportException::INVALID_STRUCTURE);
        $this->refused($this->geoSource($this->geoWorkbook(extraSheets: ['Notes' => [1 => ['x']]])), GazaGeographyImportException::INVALID_STRUCTURE);
    }

    public function test_invalid_rows_are_never_skipped(): void
    {
        $rows = $this->defaultGeoRows();
        $blank = [...array_slice($rows, 0, 2), array_fill(0, 13, null), ...array_slice($rows, 2)];
        $e = $this->refused($this->geoSource($this->geoWorkbook($blank)), GazaGeographyImportException::INVALID_RECORD);
        $this->assertContains('Row 4: blank row.', $e->problems);

        $badCode = $rows;
        $badCode[1][6] = 'A-101';
        $e = $this->refused($this->geoSource($this->geoWorkbook($badCode)), GazaGeographyImportException::INVALID_RECORD);
        $this->assertContains('Row 3: NEI_PK is not a positive integer code.', $e->problems);

        $blankName = $rows;
        $blankName[0][1] = '   ';
        $this->refused($this->geoSource($this->geoWorkbook($blankName)), GazaGeographyImportException::INVALID_RECORD);

        $title = $rows;
        $title[4][8] = 'مدرسة - اسم آخر';
        $e = $this->refused($this->geoSource($this->geoWorkbook($title)), GazaGeographyImportException::INVALID_RECORD);
        $this->assertContains('Row 6: LAN_TITLE is not "LAN_TYPE_TITLE - LAN_TITLE_NEW".', $e->problems);
    }

    public function test_contradictions_inside_the_source_abort(): void
    {
        $rows = $this->defaultGeoRows();
        // Neighborhood 100 with a second name.
        $rows[2][7] = 'اسم مختلف';
        $e = $this->refused($this->geoSource($this->geoWorkbook($rows)), GazaGeographyImportException::INVALID_RECORD);
        $this->assertContains('Row 4: NEIGHBORHOOD 100 has a different name than on row 2.', $e->problems);

        // Neighborhood 102 under a second area.
        $rows = $this->defaultGeoRows();
        $rows[4][4] = 10;
        $rows[4][5] = 'منطقة أ';
        $e = $this->refused($this->geoSource($this->geoWorkbook($rows)), GazaGeographyImportException::INVALID_RECORD);
        $this->assertStringContainsString('NEIGHBORHOOD 102 has a different parent_key', implode(' ', $e->problems));

        // A landmark code twice.
        $rows = $this->defaultGeoRows();
        $rows[] = $rows[4];
        $this->refused($this->geoSource($this->geoWorkbook($rows)), GazaGeographyImportException::INVALID_RECORD);
    }

    public function test_an_audited_finding_absent_from_the_source_aborts(): void
    {
        $findings = [['issue' => GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE, 'level' => GazaLocationLevel::LANDMARK, 'source_code' => '999', 'note' => 'x']];
        $this->refused($this->geoSource($this->geoWorkbook(), $findings), GazaGeographyImportException::SOURCE_CONFLICT);
    }

    public function test_a_revision_that_changes_imported_records_is_refused(): void
    {
        $this->import($this->geoSource($this->geoWorkbook()));
        $before = GazaLocation::orderBy('id')->get()->toArray();

        $rows = $this->defaultGeoRows();
        $rows[3][7] = 'حي الشرق - اسم معدّل';   // NEIGHBORHOOD 102 renamed
        $rows[4][7] = 'حي الشرق - اسم معدّل';
        $rows[] = $this->geoRow([2, 'محافظة ثانية'], [20, 'مدينة ب'], [20, 'منطقة ب'], [102, 'حي الشرق - اسم معدّل'], [6, 78, 'مدرسة', 'جديدة']);
        $e = $this->refused($this->geoSource($this->geoWorkbook($rows)), GazaGeographyImportException::REVISION_CONFLICT);

        $this->assertCount(1, $e->problems);
        $this->assertStringStartsWith('NEIGHBORHOOD:102: name would change', $e->problems[0]);
        $this->assertSame($before, GazaLocation::orderBy('id')->get()->toArray());
        $this->assertFalse(GazaLocation::where('level', 'LANDMARK')->where('source_code', '6')->exists(), 'no partial import');

        // A landmark moved to another neighborhood is a revision too.
        $rows = $this->defaultGeoRows();
        $rows[4] = $this->geoRow([1, 'محافظة الاختبار'], [10, 'مدينة أ'], [10, 'منطقة أ'], [101, 'حي رقم 7'], [5, 78, 'مدرسة', 'مدرسة الاختبار']);
        $e = $this->refused($this->geoSource($this->geoWorkbook($rows)), GazaGeographyImportException::REVISION_CONFLICT);
        $this->assertStringStartsWith('LANDMARK:5: parent_key would change', $e->problems[0]);
    }

    public function test_records_absent_from_a_new_source_are_reported_and_kept(): void
    {
        $this->import($this->geoSource($this->geoWorkbook()));

        $rows = array_slice($this->defaultGeoRows(), 0, 4);   // landmark 5 no longer supplied
        $report = $this->import($this->geoSource($this->geoWorkbook($rows)));

        $this->assertSame(['LANDMARK:5'], $report['missing_from_source']);
        $this->assertTrue($this->location(GazaLocationLevel::LANDMARK, '5')->is_active);
        $this->assertSame(14, GazaLocation::count());
    }

    public function test_the_import_never_touches_families_or_residences(): void
    {
        $residence = FamilyResidence::factory()->create(['governorate' => 'نص حر', 'city' => null]);
        $before = $residence->fresh()->toArray();

        $this->import($this->geoSource($this->geoWorkbook()));

        $this->assertSame($before, $residence->fresh()->toArray());
        $this->assertSame(1, FamilyResidence::count());
    }

    public function test_the_command_refuses_a_workbook_that_is_not_the_audited_version(): void
    {
        $this->artisan('famboook:import-gaza-geography', ['--path' => $this->geoWorkbook()])
            ->expectsOutputToContain('[CHECKSUM_MISMATCH]')
            ->assertFailed();

        $this->artisan('famboook:import-gaza-geography', ['--path' => sys_get_temp_dir().'/no-such-gaza-workbook.xlsx'])
            ->expectsOutputToContain('[MISSING_FILE]')
            ->assertFailed();

        $this->assertSame(0, GazaLocation::count());
    }
}
