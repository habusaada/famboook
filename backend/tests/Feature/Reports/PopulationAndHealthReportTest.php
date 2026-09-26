<?php

namespace Tests\Feature\Reports;

use App\Models\FamilyResidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports V1 — common scope, Population & Families, Health
 * (docs/03 §55b). Today is 2026-09-24.
 */
class PopulationAndHealthReportTest extends TestCase
{
    use BuildsReportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportFixtures();
    }

    /** teima(1) + halas(2) in BG07, hannun(3) in BG01, none(4) unassigned, other(5) in another Clan. */
    private function families(): array
    {
        return [
            'teima' => $this->inBranch($this->family(1, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']), 'ABU_TEIMA'),
            'halas' => $this->inBranch($this->family(2, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']), 'ABU_HALAS'),
            'hannun' => $this->inBranch($this->family(3), 'BREEM_ABU_HANNUN'),
            'none' => $this->family(4, ['displacement' => 'DISPLACED', 'location' => 'مخيم  أ']),
            'other' => $this->inOtherClan($this->family(5)),
        ];
    }

    // ------------------------------------------------------------------ scope

    public function test_scope_levels_match_dashboard_semantics(): void
    {
        $this->families();

        $this->report('population')->assertOk()
            ->assertJsonPath('data.summary.active_families', 4)
            ->assertJsonPath('data.summary.current_people', 10);
        $this->report('population', ['branch_group' => 'BG07'])->assertOk()
            ->assertJsonPath('data.summary.active_families', 2)
            ->assertJsonPath('data.summary.current_people', 3);
        $this->report('population', ['branch_group' => 'BG07', 'branch' => 'ABU_HALAS'])->assertOk()
            ->assertJsonPath('data.summary.active_families', 1)
            ->assertJsonPath('data.summary.current_people', 2)
            ->assertJsonPath('data.organization', null);

        // Same numbers as the Dashboard for the same scope.
        $dashboard = $this->actingAs($this->user)->getJson('/api/v1/dashboard?clan=AL_BREEM')->json('data.kpis');
        $report = $this->report('population')->json('data.summary');
        $this->assertSame($dashboard['active_families'], $report['active_families']);
        $this->assertSame($dashboard['current_people'], $report['current_people']);
        $this->assertSame($dashboard['displaced_families'], $report['displaced']);
    }

    public function test_invalid_scope_is_rejected_for_every_report(): void
    {
        foreach (['population', 'health', 'needs', 'assessments', 'assistance', 'data-quality'] as $report) {
            $this->report($report, ['clan' => null])->assertUnprocessable()->assertJsonValidationErrors('clan');
            $this->report($report, ['branch' => 'OTHER_BRANCH'])->assertUnprocessable()->assertJsonValidationErrors('branch');
            $this->report($report, ['branch_group' => 'BG01', 'branch' => 'ABU_HALAS'])->assertUnprocessable()->assertJsonValidationErrors('branch');
        }
    }

    // ------------------------------------------------------------- population

    public function test_population_summary_age_bands_and_locations(): void
    {
        $family = $this->family(0, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']);
        $this->addMember($family, ['birth_date' => '2024-09-24', 'gender' => 'MALE']);   // exactly 2
        $this->addMember($family, ['birth_date' => '2008-09-24']);                       // exactly 18
        $this->addMember($family, ['birth_date' => '1966-09-24', 'gender' => null]);    // exactly 60, gender unknown
        $this->addMember($family, ['birth_date' => null]);
        $notDisplaced = $this->family(1);
        $unknown = $this->family(1);
        FamilyResidence::where('family_id', $unknown->id)->update(['displacement_status' => null]);

        $data = $this->report('population')->assertOk()->json('data');

        $this->assertSame([
            'active_families' => 3, 'current_people' => 6, 'male' => 1, 'female' => 4, 'unknown_gender' => 1,
            'displaced' => 1, 'not_displaced' => 1, 'unknown_displacement' => 1,
        ], $data['summary']);
        $this->assertSame([0, 1, 0, 3, 1, 1], array_column($data['age_bands'], 'count'));
        $this->assertSame(['UNDER_2', 'AGE_2_5', 'AGE_6_17', 'AGE_18_59', 'AGE_60_PLUS', 'UNKNOWN'], array_column($data['age_bands'], 'code'));
        $this->assertSame([['location' => 'مخيم أ', 'families' => 1]], $data['top_locations']);
        $this->assertNotNull($notDisplaced);
    }

    public function test_organizational_breakdown_with_unassigned_row(): void
    {
        $this->families();

        $org = $this->report('population')->assertOk()->json('data.organization');
        $this->assertSame('CLAN', $org['level']);
        $this->assertCount(17, $org['groups']);
        $groups = collect($org['groups'])->keyBy('code');
        $this->assertSame(['families' => 2, 'people' => 3], array_intersect_key($groups['BG07'], ['families' => 1, 'people' => 1]));
        $this->assertSame(['families' => 1, 'people' => 3], array_intersect_key($groups['BG01'], ['families' => 1, 'people' => 1]));
        $this->assertCount(5, $groups['BG07']['branches']);
        $halas = collect($groups['BG07']['branches'])->firstWhere('code', 'ABU_HALAS');
        $this->assertSame([2, 1], [$halas['people'], $halas['families']]);
        $this->assertSame(['families' => 0, 'people' => 0], array_intersect_key($groups['BG17'], ['families' => 1, 'people' => 1]));
        // Families without a Branch: their own row, not attributed to a group.
        $this->assertSame(['families' => 1, 'people' => 4], $org['unassigned']);

        $group = $this->report('population', ['branch_group' => 'BG07'])->assertOk()->json('data.organization');
        $this->assertSame('BRANCH_GROUP', $group['level']);
        $this->assertSame(['BG07'], array_column($group['groups'], 'code'));
        $this->assertNull($group['unassigned']);
    }

    // ------------------------------------------------------------------ health

    public function test_health_is_aggregate_distinct_current_and_private(): void
    {
        $family = $this->inBranch($this->family(0), 'ABU_TEIMA');
        $a = $this->addMember($family);
        $b = $this->addMember($family);
        $this->healthRecord($a, 'DISABILITY');
        $this->healthRecord($a, 'CHRONIC_DISEASE', ['condition_name' => 'مرض سري تجريبي', 'details' => 'تفاصيل سرية']);
        $this->healthRecord($b, 'DISABILITY', ['disability_type_id' => 2]);
        $this->healthRecord($b, 'PREGNANCY', ['ended_at' => '2025-01-01']);
        $this->healthRecord($b, 'PREGNANCY');
        $this->healthRecord($this->addMember($family, ['life_status' => 'DECEASED', 'death_date' => '2026-01-01']), 'DISABILITY');
        $this->healthRecord($this->addMember($this->family(0)), 'BREASTFEEDING', ['ended_at' => '2026-01-01']);

        $response = $this->report('health')->assertOk();
        $health = $response->json('data.health');
        $this->assertSame([2, 1, 1, 0], [$health['people_with_disability'], $health['people_with_chronic_disease'], $health['active_pregnancy'], $health['active_breastfeeding']]);
        $this->assertEqualsCanonicalizing(
            [['code' => 'MOTOR', 'name' => 'حركية', 'people' => 1], ['code' => 'VISUAL', 'name' => 'بصرية', 'people' => 1]],
            $health['disability_types'],
        );
        foreach (['مرض سري تجريبي', 'تفاصيل سرية', 'person_code', 'full_name'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $this->report('health', ['branch' => 'BREEM_ABU_HANNUN'])->assertOk()->assertJsonPath('data.health.people_with_disability', 0);
    }

    // ------------------------------------------------------------ permissions

    public function test_report_access_and_domain_permissions(): void
    {
        $this->getJson('/api/v1/reports/population?clan=AL_BREEM')->assertUnauthorized();

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $user = $this->user($role);
            foreach (['population', 'health', 'needs', 'assessments', 'assistance', 'data-quality'] as $report) {
                $this->report($report, [], $user)->assertOk();
            }
        }

        // REPORTS_VIEWER: population and data quality only (its domain grants).
        $viewer = $this->user('REPORTS_VIEWER');
        $this->actingAs($viewer)->getJson('/api/v1/reports/meta')->assertOk()
            ->assertJsonPath('data.reports', [
                'population' => true, 'health' => false, 'needs' => false,
                'assessments' => false, 'assistance' => false, 'data-quality' => true,
            ])
            ->assertJsonPath('data.can_export', true);
        $this->report('population', [], $viewer)->assertOk();
        $this->report('data-quality', [], $viewer)->assertOk();
        foreach (['health', 'needs', 'assessments', 'assistance'] as $report) {
            $this->report($report, [], $viewer)->assertForbidden();
        }

        $familyUser = $this->user('FAMILY_USER');
        $this->report('population', [], $familyUser)->assertForbidden();
        $this->actingAs($familyUser)->getJson('/api/v1/reports/meta')->assertForbidden();
        $this->actingAs($familyUser)->getJson('/api/v1/reports/scope-options')->assertForbidden();

        $this->actingAs($viewer)->getJson('/api/v1/reports/scope-options')->assertOk()->assertJsonPath('data.0.code', 'AL_BREEM');
    }
}
