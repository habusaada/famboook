<?php

namespace Tests\Feature\Assistances;

use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Targeting criteria and the derived preview (docs/03-BUSINESS-RULES.md
 * §47b). Today is 2026-09-24 in every test.
 */
class AssistanceTargetingTest extends TestCase
{
    use BuildsAssistanceFixtures, RefreshDatabase;

    private Assistance $assistance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssistanceFixtures();
        $this->assistance = $this->openAssistance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function codes(...$families): array
    {
        $codes = array_map(fn ($f) => $f->family_code, $families);
        sort($codes);

        return $codes;
    }

    // ---------------------------------------------------------------- family size

    public function test_min_max_and_range_of_living_active_members(): void
    {
        $two = $this->family(2);
        $five = $this->family(5);
        $seven = $this->family(7);

        $this->assertSame($this->codes($five, $seven), $this->matching($this->assistance, ['min_family_members' => 5]));
        $this->assertSame($this->codes($two, $five), $this->matching($this->assistance, ['max_family_members' => 5]));
        $this->assertSame($this->codes($five), $this->matching($this->assistance, ['min_family_members' => 3, 'max_family_members' => 6]));

        $this->preview($this->assistance, ['min_family_members' => 6, 'max_family_members' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria.max_family_members');
    }

    public function test_family_size_excludes_deceased_and_inactive_memberships(): void
    {
        $family = $this->family(4);
        $this->addMember($family, ['life_status' => 'DECEASED']);
        $this->addMember($family, [], active: false);

        $this->assertSame([], $this->matching($this->assistance, ['min_family_members' => 5]));
        $this->preview($this->assistance, ['min_family_members' => 4])->assertOk()->assertJsonPath('data.0.member_count', 4);
    }

    public function test_inactive_and_archived_families_are_not_targeted(): void
    {
        $active = $this->family(5);
        $this->family(5, ['status' => 'INACTIVE']);
        $this->family(5, ['status' => 'ARCHIVED']);

        $this->assertSame($this->codes($active), $this->matching($this->assistance, ['min_family_members' => 5]));
    }

    // ---------------------------------------------------------------- displacement

    public function test_displacement_status_and_location(): void
    {
        $camp = $this->family(3, ['displacement' => 'DISPLACED', 'location' => 'مخيم الشاطئ']);
        $school = $this->family(3, ['displacement' => 'DISPLACED', 'location' => 'مدرسة الإيواء']);
        $home = $this->family(3, ['displacement' => 'NOT_DISPLACED']);

        $this->assertSame($this->codes($camp, $school), $this->matching($this->assistance, ['displacement_status' => 'DISPLACED']));
        $this->assertSame($this->codes($home), $this->matching($this->assistance, ['displacement_status' => 'NOT_DISPLACED']));
        $this->assertSame($this->codes($camp), $this->matching($this->assistance, ['displacement_location_text' => 'الشاطئ']));
        $this->assertSame($this->codes($school), $this->matching($this->assistance, ['displacement_status' => 'DISPLACED', 'displacement_location_text' => 'مدرسة']));

        $this->preview($this->assistance, ['displacement_status' => 'MOVED'])->assertUnprocessable();
    }

    // ---------------------------------------------------------------- health-derived

    public function test_child_under_two_and_minimum_count(): void
    {
        $one = $this->family(2);
        $this->addMember($one, ['birth_date' => '2025-06-01']);
        $twins = $this->family(2);
        $this->addMember($twins, ['birth_date' => '2026-01-01']);
        $this->addMember($twins, ['birth_date' => '2026-01-01']);
        $older = $this->family(2);
        // Second birthday today: no longer under two (FamilyHealthSummary rule).
        $this->addMember($older, ['birth_date' => '2024-09-24']);

        $this->assertSame($this->codes($one, $twins), $this->matching($this->assistance, ['has_child_under_two' => true]));
        $this->assertSame($this->codes($twins), $this->matching($this->assistance, ['min_children_under_two' => 2]));
        $this->assertSame($this->codes($older), $this->matching($this->assistance, ['has_child_under_two' => false]));

        $this->preview($this->assistance, ['min_children_under_two' => 2])->assertOk()
            ->assertJsonPath('data.0.indicators.children_under_two', 2);
        $this->preview($this->assistance, ['has_child_under_two' => false, 'min_children_under_two' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria.min_children_under_two');
    }

    public function test_pregnancy_breastfeeding_disability_and_chronic_disease(): void
    {
        $families = [];
        foreach (['PREGNANCY', 'BREASTFEEDING', 'DISABILITY', 'CHRONIC_DISEASE'] as $type) {
            $families[$type] = $this->family(2);
            $this->healthRecord($this->addMember($families[$type]), $type);
        }
        $none = $this->family(2);

        $map = [
            'has_pregnant_member' => 'PREGNANCY',
            'has_breastfeeding_member' => 'BREASTFEEDING',
            'has_member_with_disability' => 'DISABILITY',
            'has_member_with_chronic_disease' => 'CHRONIC_DISEASE',
        ];
        foreach ($map as $criterion => $type) {
            $this->assertSame($this->codes($families[$type]), $this->matching($this->assistance, [$criterion => true]), $criterion);
            $this->assertNotContains($families[$type]->family_code, $this->matching($this->assistance, [$criterion => false]), $criterion);
            $this->assertContains($none->family_code, $this->matching($this->assistance, [$criterion => false]), $criterion);
        }
    }

    public function test_closed_health_records_do_not_qualify(): void
    {
        $family = $this->family(2);
        $this->healthRecord($this->addMember($family), 'PREGNANCY', ['ended_at' => '2026-09-01']);

        $this->assertSame([], $this->matching($this->assistance, ['has_pregnant_member' => true]));
    }

    public function test_deceased_members_are_excluded(): void
    {
        $family = $this->family(2);
        $this->healthRecord($this->addMember($family, ['life_status' => 'DECEASED']), 'DISABILITY');
        $this->addMember($family, ['life_status' => 'DECEASED', 'birth_date' => '2026-01-01']);

        $this->assertSame([], $this->matching($this->assistance, ['has_member_with_disability' => true]));
        $this->assertSame([], $this->matching($this->assistance, ['has_child_under_two' => true]));
    }

    public function test_inactive_family_membership_is_excluded(): void
    {
        $family = $this->family(2);
        $this->healthRecord($this->addMember($family, [], active: false), 'CHRONIC_DISEASE');
        $this->addMember($family, ['birth_date' => '2026-01-01'], active: false);

        $this->assertSame([], $this->matching($this->assistance, ['has_member_with_chronic_disease' => true]));
        $this->assertSame([], $this->matching($this->assistance, ['has_child_under_two' => true]));
    }

    // ---------------------------------------------------------------- needs

    public function test_open_need_category_and_priorities(): void
    {
        $urgentFood = $this->family();
        $this->need($urgentFood, ['category' => 'FOOD', 'priority' => 'URGENT']);
        $lowFood = $this->family();
        $this->need($lowFood, ['category' => 'FOOD', 'priority' => 'LOW']);
        $highWater = $this->family();
        $this->need($highWater, ['category' => 'WATER', 'priority' => 'HIGH']);

        $this->assertSame($this->codes($urgentFood, $lowFood), $this->matching($this->assistance, ['need_category_code' => 'FOOD']));
        $this->assertSame($this->codes($urgentFood), $this->matching($this->assistance, ['need_category_code' => 'FOOD', 'need_priorities' => ['HIGH', 'URGENT']]));
        // Multi-value = OR.
        $this->assertSame($this->codes($urgentFood, $highWater), $this->matching($this->assistance, ['need_priorities' => ['HIGH', 'URGENT']]));

        $this->preview($this->assistance, ['need_category_code' => 'FOOD', 'need_priorities' => ['URGENT']])->assertOk()
            ->assertJsonPath('data.0.matching_need.category.code', 'FOOD')
            ->assertJsonPath('data.0.matching_need.priority', 'URGENT')
            ->assertJsonPath('data.0.matching_need.count', 1);

        $this->preview($this->assistance, ['need_priorities' => ['CRITICAL']])->assertUnprocessable();
    }

    public function test_resolved_needs_do_not_qualify(): void
    {
        $this->need($this->family(), ['category' => 'FOOD', 'status' => 'FULFILLED']);
        $this->need($this->family(), ['category' => 'FOOD', 'status' => 'CLOSED']);

        $this->assertSame([], $this->matching($this->assistance, ['need_category_code' => 'FOOD']));
    }

    // ---------------------------------------------------------------- assessment

    public function test_completed_assessment_rating_matches(): void
    {
        $critical = $this->family();
        $this->assessment($critical, 'SHELTER', 'CRITICAL', '2026-09-01');
        $low = $this->family();
        $this->assessment($low, 'SHELTER', 'LOW', '2026-09-01');
        $otherDomain = $this->family();
        $this->assessment($otherDomain, 'FOOD', 'CRITICAL', '2026-09-01');

        $this->assertSame($this->codes($critical), $this->matching($this->assistance, [
            'assessment_domain_code' => 'SHELTER', 'assessment_ratings' => ['HIGH', 'CRITICAL'],
        ]));
        // Domain alone: any rating in the latest result for that domain.
        $this->assertSame($this->codes($critical, $low), $this->matching($this->assistance, ['assessment_domain_code' => 'SHELTER']));

        $this->preview($this->assistance, ['assessment_domain_code' => 'SHELTER', 'assessment_ratings' => ['CRITICAL']])->assertOk()
            ->assertJsonPath('data.0.matching_assessment.domain.code', 'SHELTER')
            ->assertJsonPath('data.0.matching_assessment.rating', 'CRITICAL')
            ->assertJsonPath('data.0.matching_assessment.assessment_date', '2026-09-01');

        $this->preview($this->assistance, ['assessment_ratings' => ['HIGH']])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria.assessment_domain_code');
    }

    public function test_latest_completed_result_for_the_domain_is_used(): void
    {
        // Was CRITICAL, latest assessment of shelter says LOW → no longer matches.
        $improved = $this->family();
        $this->assessment($improved, 'SHELTER', 'CRITICAL', '2026-08-01');
        $this->assessment($improved, 'SHELTER', 'LOW', '2026-09-01');
        // Latest assessment overall did not rate SHELTER → the latest
        // assessment that DID rate it still counts.
        $unrated = $this->family();
        $this->assessment($unrated, 'SHELTER', 'HIGH', '2026-08-01');
        $this->assessment($unrated, 'FOOD', 'LOW', '2026-09-10');
        // Same date: the later-completed one wins.
        $sameDay = $this->family();
        $this->assessment($sameDay, 'SHELTER', 'LOW', '2026-09-05');
        Carbon::setTestNow('2026-09-24 11:00:00');
        $this->assessment($sameDay, 'SHELTER', 'CRITICAL', '2026-09-05');

        $this->assertSame($this->codes($unrated, $sameDay), $this->matching($this->assistance, [
            'assessment_domain_code' => 'SHELTER', 'assessment_ratings' => ['HIGH', 'CRITICAL'],
        ]));
    }

    public function test_draft_assessments_are_ignored(): void
    {
        $family = $this->family();
        $this->assessment($family, 'SHELTER', 'LOW', '2026-08-01');
        $this->assessment($family, 'SHELTER', 'CRITICAL', '2026-09-20', completed: false);

        $this->assertSame([], $this->matching($this->assistance, ['assessment_domain_code' => 'SHELTER', 'assessment_ratings' => ['CRITICAL']]));
        $this->assertSame($this->codes($family), $this->matching($this->assistance, ['assessment_domain_code' => 'SHELTER', 'assessment_ratings' => ['LOW']]));
    }

    // ---------------------------------------------------------------- composition

    public function test_supplied_criteria_combine_with_and(): void
    {
        $all = $this->family(6, ['displacement' => 'DISPLACED']);
        $this->need($all, ['category' => 'FOOD', 'priority' => 'HIGH']);
        $notDisplaced = $this->family(6);
        $this->need($notDisplaced, ['category' => 'FOOD', 'priority' => 'HIGH']);
        $small = $this->family(2, ['displacement' => 'DISPLACED']);
        $this->need($small, ['category' => 'FOOD', 'priority' => 'URGENT']);
        $noNeed = $this->family(6, ['displacement' => 'DISPLACED']);

        $this->assertSame($this->codes($all), $this->matching($this->assistance, [
            'min_family_members' => 5,
            'displacement_status' => 'DISPLACED',
            'need_category_code' => 'FOOD',
            'need_priorities' => ['HIGH', 'URGENT'],
        ]));
        $this->assertCount(4, $this->matching($this->assistance, []));
    }

    public function test_unknown_criteria_are_rejected(): void
    {
        $this->preview($this->assistance, ['income_below' => 100])->assertUnprocessable()->assertJsonValidationErrors('criteria');
    }

    public function test_preview_persists_nothing(): void
    {
        $this->family(5, ['displacement' => 'DISPLACED']);
        $before = $this->assistance->fresh()->targeting_criteria;

        $this->preview($this->assistance, ['min_family_members' => 5])->assertOk()->assertJsonPath('meta.total', 1);

        $this->assertSame(0, AssistanceBeneficiary::count());
        $this->assertSame(0, FamilyActivity::count());
        $this->assertSame($before, $this->assistance->fresh()->targeting_criteria);
    }

    public function test_preview_is_paginated_and_flags_already_nominated(): void
    {
        $families = collect(range(1, 3))->map(fn () => $this->family(5));
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/manual", ['family_code' => $families[0]->family_code])->assertCreated();

        $page = $this->preview($this->assistance, ['min_family_members' => 5], null, ['per_page' => 2])->assertOk();
        $page->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);

        $rows = collect($this->preview($this->assistance, ['min_family_members' => 5])->json('data'))->keyBy('family_code');
        $this->assertTrue($rows[$families[0]->family_code]['already_nominated']);
        $this->assertFalse($rows[$families[1]->family_code]['already_nominated']);
    }

    public function test_preview_exposes_only_minimal_indicators(): void
    {
        $family = $this->family(2, ['national_id' => '9990003331', 'mobile' => '0799993331', 'displacement' => 'DISPLACED']);
        $member = $this->addMember($family, ['birth_date' => '2026-01-01']);
        $this->healthRecord($member, 'CHRONIC_DISEASE', ['condition_name' => 'مرض سري اختباري', 'details' => 'تفاصيل سرية اختبارية']);
        $this->healthRecord($this->addMember($family), 'PREGNANCY', ['details' => 'ملاحظة حمل سرية']);
        $this->need($family, ['category' => 'FOOD', 'priority' => 'URGENT', 'title' => 'عنوان احتياج', 'description' => 'وصف احتياج سري']);
        $this->assessment($family, 'SHELTER', 'CRITICAL', '2026-09-01', notes: 'ملاحظة تقييم سرية');

        $response = $this->preview($this->assistance, [
            'has_member_with_chronic_disease' => true,
            'has_pregnant_member' => true,
            'has_child_under_two' => true,
            'need_category_code' => 'FOOD',
            'assessment_domain_code' => 'SHELTER',
        ])->assertOk();

        $response->assertJsonPath('data.0.indicators.has_member_with_chronic_disease', true)
            ->assertJsonPath('data.0.indicators.has_pregnant_member', true)
            ->assertJsonPath('data.0.indicators.children_under_two', 1);

        $raw = $response->getContent();
        foreach (['9990003331', '0799993331', 'national_id', 'mobile', 'مرض سري اختباري', 'تفاصيل سرية اختبارية',
            'ملاحظة حمل سرية', 'وصف احتياج سري', 'عنوان احتياج', 'ملاحظة تقييم سرية', 'person_code', 'family_id',
            'birth_date', '"id":'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
    }

    public function test_preview_permissions(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->preview($this->assistance, [], $this->user($role))->assertOk();
        }
        foreach (['REVIEWER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->preview($this->assistance, [], $this->user($role))->assertForbidden();
        }
    }
}
