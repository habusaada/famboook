<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports V1 — Needs lifecycle report and Assessments latest-domain-state
 * report with drill-down (docs/03 §55b). Today is 2026-09-24.
 */
class NeedsAndAssessmentsReportTest extends TestCase
{
    use BuildsReportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportFixtures();
    }

    // ------------------------------------------------------------------ needs

    private function needFixtures(): array
    {
        $a = $this->inBranch($this->family(1, ['name' => 'رب أسرة أ']), 'ABU_TEIMA');
        $b = $this->family(2);
        $person = $b->memberships()->orderBy('id')->skip(1)->first()->person;

        $this->need($a, ['priority' => 'URGENT', 'category' => 'FOOD', 'title' => 'حاجة أ1', 'description' => 'وصف سري']);
        $this->need($a, ['priority' => 'HIGH', 'category' => 'FOOD', 'status' => 'FULFILLED', 'title' => 'حاجة أ2']);
        $this->need($b, ['priority' => 'LOW', 'category' => 'SHELTER', 'status' => 'CLOSED', 'title' => 'حاجة ب1']);
        $this->need($b, ['priority' => 'URGENT', 'category' => 'SHELTER', 'person_id' => $person->id, 'title' => 'حاجة ب2']);
        $this->need($this->inOtherClan($this->family(1)), ['title' => 'حاجة خارج النطاق']);
        $this->need($this->family(1, ['status' => 'INACTIVE']), ['title' => 'حاجة أسرة غير نشطة']);

        return [$a, $b, $person];
    }

    public function test_needs_lifecycle_summary_keeps_closed_apart_from_fulfilled(): void
    {
        $this->needFixtures();

        $summary = $this->report('needs')->assertOk()->json('data.summary');

        $this->assertSame([4, 2, 1, 1], [$summary['total'], $summary['open'], $summary['fulfilled'], $summary['closed']]);
        $this->assertSame([2, 1, 0, 1], array_column($summary['by_priority'], 'count'));
        $this->assertSame(['family' => 3, 'person' => 1], $summary['by_target']);
        $this->assertEqualsCanonicalizing(['FOOD' => 2, 'SHELTER' => 2], array_column($summary['by_category'], 'count', 'code'));
    }

    public function test_needs_filters_apply_to_summary_and_rows(): void
    {
        [, , $person] = $this->needFixtures();

        $this->report('needs', ['status' => 'OPEN'])->assertOk()
            ->assertJsonPath('data.summary.total', 2)->assertJsonPath('data.rows.meta.total', 2);
        $this->report('needs', ['priority' => 'URGENT'])->assertOk()->assertJsonPath('data.summary.total', 2);
        $this->report('needs', ['category' => 'SHELTER'])->assertOk()->assertJsonPath('data.summary.total', 2);
        $rows = $this->report('needs', ['target' => 'PERSON'])->assertOk()->json('data.rows.data');
        $this->assertCount(1, $rows);
        $this->assertSame(['type' => 'PERSON', 'code' => $person->person_code], ['type' => $rows[0]['target_type'], 'code' => $rows[0]['target']['code']]);
        $this->report('needs', ['branch' => 'ABU_TEIMA'])->assertOk()->assertJsonPath('data.summary.total', 2);

        $this->report('needs', ['status' => 'DONE'])->assertStatus(422);
        $this->report('needs', ['priority' => 'VERY_HIGH'])->assertUnprocessable();
        $this->report('needs', ['target' => 'CLAN'])->assertUnprocessable();
    }

    public function test_needs_rows_are_paginated_and_safe(): void
    {
        [$a] = $this->needFixtures();

        $page1 = $this->report('needs', ['per_page' => 3])->assertOk();
        $page1->assertJsonPath('data.rows.meta.total', 4)->assertJsonPath('data.rows.meta.last_page', 2)->assertJsonCount(3, 'data.rows.data');
        $page2 = $this->report('needs', ['per_page' => 3, 'page' => 2])->assertOk()->assertJsonCount(1, 'data.rows.data');
        $this->report('needs', ['per_page' => 500])->assertUnprocessable();

        $row = collect([...$page1->json('data.rows.data'), ...$page2->json('data.rows.data')])->firstWhere('title', 'حاجة أ1');
        $this->assertSame(['id', 'title', 'category', 'priority', 'status', 'target_type', 'target', 'family_code', 'created_date', 'resolved_date'], array_keys($row));
        $this->assertSame(['code' => $a->family_code, 'name' => 'رب أسرة أ 0'], $row['target']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['id']);
        foreach (['وصف سري', 'closure_reason', 'description', 'national_id', 'mobile'] as $secret) {
            $this->assertStringNotContainsString($secret, $page1->getContent().$page2->getContent());
        }
    }

    // ------------------------------------------------------------ assessments

    public function test_assessment_domains_use_latest_completed_state_and_lifecycle_counts(): void
    {
        $a = $this->inBranch($this->family(1), 'ABU_TEIMA');
        $b = $this->family(1);
        $this->assessment($a, 'SHELTER', 'HIGH', '2026-09-01');
        $this->assessment($a, 'SHELTER', 'LOW', '2026-09-10', notes: 'ملاحظة سرية');
        $this->assessment($a, 'SHELTER', 'CRITICAL', '2026-09-20', completed: false);
        $this->assessment($b, 'FOOD', 'NONE', '2026-09-05');

        $response = $this->report('assessments')->assertOk();
        $domains = collect($response->json('data.domains'))->keyBy('code');
        $this->assertSame(['NONE' => 0, 'LOW' => 1, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0], $domains['SHELTER']['ratings']);
        $this->assertSame([1, 1], [$domains['SHELTER']['assessed_families'], $domains['SHELTER']['not_assessed_families']]);
        // B never rated SHELTER: not assessed, not NONE.
        $this->assertSame(1, $domains['FOOD']['ratings']['NONE']);
        $response->assertJsonPath('data.lifecycle', ['draft' => 1, 'completed' => 3]);
        $this->assertStringNotContainsString('ملاحظة سرية', $response->getContent());
    }

    public function test_assessment_drill_down_lists_exact_bucket_families(): void
    {
        $a = $this->inBranch($this->family(1, ['name' => 'رب أ']), 'ABU_TEIMA');
        $b = $this->family(1);
        $c = $this->family(1);
        $this->assessment($a, 'SHELTER', 'HIGH', '2026-09-01');
        $a2 = $this->assessment($a, 'SHELTER', 'CRITICAL', '2026-09-10', notes: 'ملاحظة سرية');
        $this->assessment($b, 'SHELTER', 'HIGH', '2026-09-05');
        $this->assessment($c, 'FOOD', 'LOW', '2026-09-05');

        $critical = $this->report('assessments/families', ['domain' => 'SHELTER', 'rating' => 'CRITICAL'])->assertOk();
        $this->assertSame([[
            'family_code' => $a->family_code,
            'household_head' => 'رب أ 0',
            'branch' => 'أبو تيمة',
            'assessment_id' => $a2->uuid,
            'assessment_date' => '2026-09-10',
            'rating' => 'CRITICAL',
        ]], $critical->json('data.rows.data'));
        $this->assertStringNotContainsString('ملاحظة سرية', $critical->getContent());

        // A's older HIGH is superseded: only B is HIGH now.
        $high = $this->report('assessments/families', ['domain' => 'SHELTER', 'rating' => 'HIGH'])->json('data.rows.data');
        $this->assertSame([$b->family_code], array_column($high, 'family_code'));

        $notAssessed = $this->report('assessments/families', ['domain' => 'SHELTER', 'rating' => 'NOT_ASSESSED'])->json('data.rows.data');
        $this->assertSame([$c->family_code], array_column($notAssessed, 'family_code'));
        $this->assertSame('NOT_ASSESSED', $notAssessed[0]['rating']);
        $this->assertSame([], $this->report('assessments/families', ['domain' => 'SHELTER', 'rating' => 'NONE'])->json('data.rows.data'));

        $this->report('assessments/families', ['domain' => 'SHELTER', 'rating' => 'CRITICAL', 'branch' => 'BREEM_ABU_HANNUN'])
            ->assertOk()->assertJsonPath('data.rows.meta.total', 0);
        $this->report('assessments/families', ['domain' => 'NOPE', 'rating' => 'HIGH'])->assertStatus(422);
        $this->report('assessments/families', ['domain' => 'SHELTER'])->assertStatus(422);
    }
}
