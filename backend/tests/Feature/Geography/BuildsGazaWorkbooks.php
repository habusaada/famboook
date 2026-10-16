<?php

namespace Tests\Feature\Geography;

use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Support\Geography\GazaGeographySource;
use Tests\Support\SyntheticXlsx;

/**
 * SYNTHETIC Gaza-shaped workbooks for the geography import tests. Every
 * name and code is invented; the official MoSD workbook is only used by
 * OfficialGazaWorkbookTest.
 *
 * The default dataset has 2 governorates, 2 cities, 2 areas, 3
 * neighborhoods and 5 landmarks (14 records), and reproduces each Stage 1A
 * pattern: an area code equal to a city code, a trailing space, two
 * same-parent neighborhoods differing only by a space, two same-type
 * landmarks of one neighborhood whose names differ only by a trailing space,
 * an unusual landmark type and a parent-place mismatch (audited).
 */
trait BuildsGazaWorkbooks
{
    /** @var list<string> */
    private array $workbooks = [];

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * One source row: [gov, city, area, neighborhood] as [code, name] pairs,
     * then the landmark [code, type code, type name, own name].
     *
     * @return list<mixed>
     */
    protected function geoRow(array $gov, array $city, array $area, array $nei, array $landmark): array
    {
        [$lanCode, $typeCode, $typeName, $lanName] = $landmark;

        return [
            $gov[0], $gov[1], $city[0], $city[1], $area[0], $area[1], $nei[0], $nei[1],
            $typeName.' - '.$lanName, $lanCode, $typeCode, $typeName, $lanName,
        ];
    }

    /** @return list<list<mixed>> */
    protected function defaultGeoRows(): array
    {
        $gov1 = [1, 'محافظة الاختبار'];
        $gov2 = [2, 'محافظة ثانية'];
        $city1 = [10, 'مدينة أ'];
        $city2 = [20, 'مدينة ب'];
        // Area codes deliberately equal city codes (cross-level reuse).
        $area1 = [10, 'منطقة أ'];
        $area2 = [20, 'منطقة ب'];
        $nei100 = [100, 'حي رقم 7 '];   // trailing space kept as supplied
        $nei101 = [101, 'حي رقم 7'];
        $nei102 = [102, 'حي الشرق - مدينة أ'];

        return [
            $this->geoRow($gov1, $city1, $area1, $nei100, [1, 76, 'مصلى', 'النور']),
            $this->geoRow($gov1, $city1, $area1, $nei101, [2, 76, 'مصلى', 'النور']),
            $this->geoRow($gov1, $city1, $area1, $nei100, [3, 76, 'مصلى', 'النور ']),
            $this->geoRow($gov2, $city2, $area2, $nei102, [4, 8, 'نوع غير مألوف', 'نقطة']),
            $this->geoRow($gov2, $city2, $area2, $nei102, [5, 78, 'مدرسة', 'مدرسة الاختبار']),
        ];
    }

    /**
     * @param  list<list<mixed>>|null  $rows  data rows (default dataset when null)
     * @param  list<string>|null  $header
     * @param  array<string, array<int, list<mixed>>>  $extraSheets
     */
    protected function geoWorkbook(?array $rows = null, ?array $header = null, array $extraSheets = [], string $sheet = GazaGeographySource::SHEET): string
    {
        $sheetRows = [1 => $header ?? GazaGeographySource::COLUMNS];
        foreach ($rows ?? $this->defaultGeoRows() as $i => $row) {
            $sheetRows[$i + 2] = $row;
        }
        $path = SyntheticXlsx::write([$sheet => $sheetRows] + $extraSheets);
        $this->workbooks[] = $path;

        return $path;
    }

    /** A source definition accepting exactly this file, with the synthetic audited findings. */
    protected function geoSource(string $path, ?array $auditedFindings = null): GazaGeographySource
    {
        return new GazaGeographySource($path, (string) hash_file('sha256', $path), $auditedFindings ?? $this->syntheticFindings());
    }

    /** @return list<array{issue: GazaLocationReviewIssue, level: GazaLocationLevel, source_code: string, note: string}> */
    protected function syntheticFindings(): array
    {
        return [
            ['issue' => GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE, 'level' => GazaLocationLevel::LANDMARK, 'source_code' => '4', 'note' => 'Synthetic unusual type.'],
            ['issue' => GazaLocationReviewIssue::PARENT_PLACE_MISMATCH, 'level' => GazaLocationLevel::NEIGHBORHOOD, 'source_code' => '102', 'note' => 'Synthetic parent mismatch.'],
        ];
    }
}
