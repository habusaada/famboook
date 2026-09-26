<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\User;
use Database\Seeders\ClanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Assistances\BuildsExecutionFixtures;
use ZipArchive;

/**
 * Reports V1 fixtures: the Assistance/household fixtures (today is
 * 2026-09-24), the approved Al-Breem taxonomy and a second Clan. All data
 * is synthetic.
 */
trait BuildsReportFixtures
{
    use BuildsExecutionFixtures;

    protected Clan $otherClan;

    protected function setUpReportFixtures(): void
    {
        $this->setUpExecutionFixtures();
        $this->seed(ClanSeeder::class);

        $this->otherClan = Clan::create(['code' => 'TEST_CLAN', 'name' => 'عشيرة تجريبية أخرى']);
        $group = BranchGroup::create(['clan_id' => $this->otherClan->id, 'code' => 'G01', 'sort_order' => 1]);
        Branch::create(['branch_group_id' => $group->id, 'clan_id' => $this->otherClan->id, 'code' => 'OTHER_BRANCH', 'name' => 'فرع آخر']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function report(string $path, array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)
            ->getJson("/api/v1/reports/{$path}?".http_build_query(['clan' => Clan::AL_BREEM, ...$query]));
    }

    protected function inBranch(Family $family, string $branchCode): Family
    {
        $family->update(['branch_id' => Branch::where('code', $branchCode)->value('id')]);

        return $family;
    }

    protected function inOtherClan(Family $family): Family
    {
        $family->update(['clan_id' => $this->otherClan->id, 'branch_id' => null]);

        return $family;
    }

    /** Downloads an export and returns [response, sheets: name => rows]. */
    protected function exportOf(string $report, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->user)
            ->get("/api/v1/reports/{$report}/export?".http_build_query(['clan' => Clan::AL_BREEM, ...$query]));
        $response->assertOk();

        return [$response, $this->readXlsx($response->getContent())];
    }

    /**
     * Minimal XLSX reader for assertions: sheet name => rows of cell
     * values (strings; numbers as strings). Also asserts every sheet is
     * right-to-left.
     *
     * @return array<string, list<list<string|null>>>
     */
    protected function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'not a zip');

        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        $i = 1;
        foreach ($workbook->sheets->sheet as $sheet) {
            $xml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            $this->assertStringContainsString('rightToLeft="1"', $xml);
            $doc = simplexml_load_string($xml);
            $rows = [];
            foreach ($doc->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $cells[] = isset($c->is) ? (string) $c->is->t : (isset($c->v) ? (string) $c->v : null);
                }
                $rows[] = $cells;
            }
            $sheets[(string) $sheet['name']] = $rows;
            $i++;
        }
        $zip->close();
        @unlink($path);

        return $sheets;
    }

    /** Raw XML of every part, for "must not contain" privacy assertions. */
    protected function rawXlsx(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $raw = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $raw .= $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $raw;
    }
}
