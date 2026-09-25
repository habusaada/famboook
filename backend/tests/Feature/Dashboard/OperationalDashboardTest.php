<?php

namespace Tests\Feature\Dashboard;

use App\Enums\FamilyActivityType;
use App\Models\AssistanceDelivery;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyActivityLog;
use Database\Seeders\ClanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Feature\Assistances\BuildsExecutionFixtures;
use Tests\TestCase;

/**
 * Operational Dashboard V1 (docs/03 §55a, docs/06 §59a). "Today" is
 * 2026-09-24 (BuildsAssistanceFixtures). All data is synthetic.
 */
class OperationalDashboardTest extends TestCase
{
    use BuildsExecutionFixtures;
    use RefreshDatabase;

    private Clan $otherClan;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function dash(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->getJson('/api/v1/dashboard?'.http_build_query(['clan' => Clan::AL_BREEM, ...$query]));
    }

    private function inBranch(Family $family, string $branchCode): Family
    {
        $family->update(['branch_id' => Branch::where('code', $branchCode)->value('id')]);

        return $family;
    }

    private function inOtherClan(Family $family): Family
    {
        $family->update(['clan_id' => $this->otherClan->id, 'branch_id' => null]);

        return $family;
    }

    /** A person with the given attributes and an active membership. */
    private function member(Family $family, array $attributes): Person
    {
        return $this->addMember($family, $attributes);
    }

    // ------------------------------------------------------------------ scope

    /** @return array<string, Family> */
    private function scopedFamilies(): array
    {
        return [
            // BG07 (five branches): two families on different branches.
            'teima' => $this->inBranch($this->family(1), 'ABU_TEIMA'),
            'halas' => $this->inBranch($this->family(2), 'ABU_HALAS'),
            // BG01 (single branch).
            'hannun' => $this->inBranch($this->family(3), 'BREEM_ABU_HANNUN'),
            // No Branch yet.
            'none' => $this->family(4),
            // Another Clan: never counted under AL_BREEM.
            'other' => $this->inOtherClan($this->family(5)),
        ];
    }

    public function test_clan_scope_includes_families_without_branch(): void
    {
        $this->scopedFamilies();

        $this->dash()->assertOk()
            ->assertJsonPath('data.scope.clan.code', Clan::AL_BREEM)
            ->assertJsonPath('data.scope.branch_group', null)
            ->assertJsonPath('data.kpis.active_families', 4)
            ->assertJsonPath('data.kpis.current_people', 10);
    }

    public function test_branch_group_scope_aggregates_all_its_branches_and_excludes_unassigned(): void
    {
        $this->scopedFamilies();

        $this->dash(['branch_group' => 'BG07'])->assertOk()
            ->assertJsonPath('data.scope.branch_group.code', 'BG07')
            ->assertJsonPath('data.scope.branch_group.name', null)
            ->assertJsonPath('data.scope.branch_group.display_name', 'أبو تيمة / أبو حلس / الطرشة / أبو سالم / الشيبي')
            ->assertJsonPath('data.kpis.active_families', 2)
            ->assertJsonPath('data.kpis.current_people', 3);

        $this->dash(['branch_group' => 'BG01'])->assertOk()
            ->assertJsonPath('data.kpis.active_families', 1)
            ->assertJsonPath('data.kpis.current_people', 3);

        // A group without families is empty, not the whole Clan.
        $this->dash(['branch_group' => 'BG17'])->assertOk()
            ->assertJsonPath('data.kpis.active_families', 0)
            ->assertJsonPath('data.kpis.current_people', 0);
    }

    public function test_branch_scope_narrows_to_one_branch(): void
    {
        $this->scopedFamilies();

        $this->dash(['branch_group' => 'BG07', 'branch' => 'ABU_HALAS'])->assertOk()
            ->assertJsonPath('data.scope.branch.code', 'ABU_HALAS')
            ->assertJsonPath('data.kpis.active_families', 1)
            ->assertJsonPath('data.kpis.current_people', 2);

        // Branch without group: the group context is derived.
        $this->dash(['branch' => 'ABU_TEIMA'])->assertOk()
            ->assertJsonPath('data.scope.branch_group.code', 'BG07')
            ->assertJsonPath('data.kpis.active_families', 1)
            ->assertJsonPath('data.kpis.current_people', 1);
    }

    public function test_invalid_scope_combinations_are_rejected(): void
    {
        $this->dash(['clan' => null])->assertUnprocessable()->assertJsonValidationErrors('clan');
        $this->dash(['clan' => 'NO_SUCH_CLAN'])->assertUnprocessable()->assertJsonValidationErrors('clan');
        // Group / branch of another Clan.
        $this->dash(['branch_group' => 'G01'])->assertUnprocessable()->assertJsonValidationErrors('branch_group');
        $this->dash(['branch' => 'OTHER_BRANCH'])->assertUnprocessable()->assertJsonValidationErrors('branch');
        $this->dash(['clan' => 'TEST_CLAN', 'branch_group' => 'BG07'])->assertUnprocessable()->assertJsonValidationErrors('branch_group');
        // Branch outside the selected group.
        $this->dash(['branch_group' => 'BG01', 'branch' => 'ABU_HALAS'])->assertUnprocessable()->assertJsonValidationErrors('branch');
    }

    public function test_scope_options_list_the_hierarchy_without_clan_view(): void
    {
        $viewer = $this->user('REPORTS_VIEWER');
        $this->assertFalse($viewer->can('clan.view'));

        $clans = $this->actingAs($viewer)->getJson('/api/v1/dashboard/scope-options')->assertOk()->json('data');
        $breem = collect($clans)->firstWhere('code', Clan::AL_BREEM);
        $this->assertCount(17, $breem['branch_groups']);
        $this->assertSame('أبو ديب', $breem['branch_groups'][9]['display_name']);
        $this->assertCount(5, $breem['branch_groups'][6]['branches']);
    }

    // ------------------------------------------------------------- population

    public function test_current_population_semantics(): void
    {
        $family = $this->family(2);
        // Inactive membership, deceased member, soft-deleted person: excluded.
        $this->addMember($family, [], active: false);
        $this->member($family, ['life_status' => 'DECEASED', 'death_date' => '2026-01-01']);
        $this->member($family, [])->delete();
        // Non-ACTIVE and soft-deleted families: excluded with their members.
        $this->family(3, ['status' => 'INACTIVE']);
        $this->family(3, ['status' => 'ARCHIVED']);
        $this->family(3)->delete();

        $this->dash()->assertOk()
            ->assertJsonPath('data.kpis.active_families', 1)
            ->assertJsonPath('data.kpis.current_people', 2)
            ->assertJsonPath('data.demographics.total', 2);
    }

    // ----------------------------------------------------------- demographics

    public function test_gender_and_age_bands_with_exact_boundaries(): void
    {
        $family = $this->family(0);
        $born = fn (string $date, string $gender = 'FEMALE') => $this->member($family, ['birth_date' => $date, 'gender' => $gender]);

        // Today is 2026-09-24.
        $born('2025-09-25');                 // 0 → UNDER_2
        $born('2024-09-25', 'MALE');         // 1 (2 tomorrow) → UNDER_2
        $born('2024-09-24', 'MALE');         // exactly 2 → AGE_2_5
        $born('2020-09-25');                 // 5 → AGE_2_5
        $born('2020-09-24');                 // exactly 6 → AGE_6_17
        $born('2008-09-25', 'MALE');         // 17 → AGE_6_17
        $born('2008-09-24');                 // exactly 18 → AGE_18_59
        $born('1966-09-25');                 // 59 → AGE_18_59
        $born('1966-09-24', 'MALE');         // exactly 60 → AGE_60_PLUS
        $born('1930-01-01');                 // 96 → AGE_60_PLUS
        $this->member($family, ['birth_date' => null]);   // → UNKNOWN

        $data = $this->dash()->assertOk()->json('data.demographics');

        $this->assertSame(11, $data['total']);
        $this->assertSame(['male' => 4, 'female' => 7, 'unknown' => 0], $data['gender']);
        $this->assertSame([
            ['code' => 'UNDER_2', 'count' => 2],
            ['code' => 'AGE_2_5', 'count' => 2],
            ['code' => 'AGE_6_17', 'count' => 2],
            ['code' => 'AGE_18_59', 'count' => 2],
            ['code' => 'AGE_60_PLUS', 'count' => 2],
            ['code' => 'UNKNOWN', 'count' => 1],
        ], $data['age_bands']);
    }

    // ----------------------------------------------------------- displacement

    public function test_displacement_breakdown_and_exact_locations(): void
    {
        $this->family(1, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']);
        $this->family(1, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']);
        // Spelled differently: kept separate (no fuzzy merging).
        $this->family(1, ['displacement' => 'DISPLACED', 'location' => 'مخيم  أ']);
        $this->family(1, ['displacement' => 'DISPLACED']);
        $this->family(1, ['displacement' => 'NOT_DISPLACED']);
        $unknown = $this->family(1);
        FamilyResidence::where('family_id', $unknown->id)->update(['displacement_status' => null]);
        $noResidence = $this->family(1);
        FamilyResidence::where('family_id', $noResidence->id)->delete();
        $this->inOtherClan($this->family(1, ['displacement' => 'DISPLACED', 'location' => 'مخيم أ']));

        $response = $this->dash()->assertOk()
            ->assertJsonPath('data.kpis.displaced_families', 4)
            ->assertJsonPath('data.displacement.total_families', 7)
            ->assertJsonPath('data.displacement.displaced', 4)
            ->assertJsonPath('data.displacement.not_displaced', 1)
            ->assertJsonPath('data.displacement.unknown', 2);

        $this->assertSame([
            ['location' => 'مخيم أ', 'families' => 2],
            ['location' => 'مخيم  أ', 'families' => 1],
        ], $response->json('data.displacement.top_locations'));
    }

    // ----------------------------------------------------------------- health

    public function test_health_counts_distinct_current_people_with_active_records(): void
    {
        $family = $this->inBranch($this->family(0), 'ABU_TEIMA');
        $a = $this->member($family, []);
        $b = $this->member($family, []);
        $c = $this->member($family, []);

        $this->healthRecord($a, 'DISABILITY');
        $this->healthRecord($a, 'CHRONIC_DISEASE');
        $this->healthRecord($a, 'CHRONIC_DISEASE', ['condition_name' => 'مرض ثان', 'ended_at' => '2026-01-01']);
        $this->healthRecord($b, 'DISABILITY', ['disability_type_id' => 2]);
        // Several pregnancy records for one person: one person.
        $this->healthRecord($c, 'PREGNANCY', ['ended_at' => '2025-01-01']);
        $this->healthRecord($c, 'PREGNANCY');
        // Ended only: not current.
        $this->healthRecord($b, 'BREASTFEEDING', ['ended_at' => '2026-02-01']);
        // Deceased / inactive member / out of scope: excluded.
        $this->healthRecord($this->member($family, ['life_status' => 'DECEASED', 'death_date' => '2026-01-01']), 'DISABILITY');
        $this->healthRecord($this->addMember($family, [], active: false), 'DISABILITY');
        $this->healthRecord($this->member($this->inBranch($this->family(0), 'BREEM_ABU_HANNUN'), []), 'DISABILITY');

        $health = $this->dash(['branch' => 'ABU_TEIMA'])->assertOk()->json('data.health');

        $this->assertSame(2, $health['people_with_disability']);
        $this->assertSame(1, $health['people_with_chronic_disease']);
        $this->assertSame(1, $health['active_pregnancy']);
        $this->assertSame(0, $health['active_breastfeeding']);
        $this->assertEqualsCanonicalizing(
            [['code' => 'MOTOR', 'name' => 'حركية', 'people' => 1], ['code' => 'VISUAL', 'name' => 'بصرية', 'people' => 1]],
            $health['disability_types'],
        );

        $this->dash()->assertOk()->assertJsonPath('data.health.people_with_disability', 3);
    }

    // ------------------------------------------------------------------ needs

    public function test_open_needs_by_priority_and_category(): void
    {
        $a = $this->inBranch($this->family(1), 'ABU_TEIMA');
        $b = $this->family(1);
        $this->need($a, ['priority' => 'URGENT', 'category' => 'FOOD']);
        $this->need($a, ['priority' => 'HIGH', 'category' => 'FOOD']);
        $this->need($b, ['priority' => 'LOW', 'category' => 'SHELTER']);
        $this->need($b, ['priority' => 'URGENT', 'status' => 'FULFILLED']);
        $this->need($b, ['priority' => 'URGENT', 'status' => 'CLOSED']);
        $this->need($this->family(1, ['status' => 'INACTIVE']), ['priority' => 'URGENT']);
        $this->need($this->inOtherClan($this->family(1)), ['priority' => 'URGENT']);

        $needs = $this->dash()->assertOk()->assertJsonPath('data.kpis.open_needs', 3)->json('data.needs');
        $this->assertSame(3, $needs['open']);
        $this->assertSame(2, $needs['families_with_open_needs']);
        $this->assertSame([
            ['priority' => 'URGENT', 'count' => 1],
            ['priority' => 'HIGH', 'count' => 1],
            ['priority' => 'MEDIUM', 'count' => 0],
            ['priority' => 'LOW', 'count' => 1],
        ], $needs['by_priority']);
        $this->assertSame('FOOD', $needs['top_categories'][0]['code']);
        $this->assertSame(2, $needs['top_categories'][0]['count']);
        $this->assertSame(['code' => 'SHELTER', 'count' => 1], array_intersect_key($needs['top_categories'][1], ['code' => 1, 'count' => 1]));

        $this->dash(['branch' => 'ABU_TEIMA'])->assertOk()->assertJsonPath('data.kpis.open_needs', 2);
    }

    // ------------------------------------------------------------ assessments

    public function test_assessments_use_latest_completed_result_per_domain(): void
    {
        $a = $this->family(1);
        $b = $this->family(1);
        $c = $this->family(1);

        // A: SHELTER HIGH then LOW (latest wins); a later assessment that
        // rates only FOOD does not hide it; a later DRAFT is ignored.
        $this->assessment($a, 'SHELTER', 'HIGH', '2026-09-01');
        $this->assessment($a, 'SHELTER', 'LOW', '2026-09-10');
        $this->assessment($a, 'FOOD', 'CRITICAL', '2026-09-20');
        $this->assessment($a, 'SHELTER', 'CRITICAL', '2026-09-22', completed: false);
        // B: only FOOD rated — not assessed for SHELTER (not NONE).
        $this->assessment($b, 'FOOD', 'MEDIUM', '2026-09-05');
        // C: SHELTER NONE.
        $this->assessment($c, 'SHELTER', 'NONE', '2026-09-05');
        // Out of scope.
        $this->assessment($this->inOtherClan($this->family(1)), 'SHELTER', 'CRITICAL', '2026-09-05');

        $domains = collect($this->dash()->assertOk()->json('data.assessments.domains'))->keyBy('code');

        $this->assertSame(['NONE' => 1, 'LOW' => 1, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0], $domains['SHELTER']['ratings']);
        $this->assertSame(2, $domains['SHELTER']['assessed_families']);
        $this->assertSame(1, $domains['SHELTER']['not_assessed_families']);
        $this->assertSame(['NONE' => 0, 'LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 0, 'CRITICAL' => 1], $domains['FOOD']['ratings']);
        $this->assertSame(0, $domains['WASH']['assessed_families']);
        $this->assertSame(3, $domains['WASH']['not_assessed_families']);
        $this->assertCount(8, $domains);
    }

    // ------------------------------------------------------------- assistance

    public function test_internal_assistance_delivery_states_by_beneficiary_family(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $delivered = $this->household(['national_id' => '810000001']);
        $reversed = $this->household(['national_id' => '810000002']);
        $notDelivered = $this->household(['national_id' => '810000003']);
        $nominated = $this->household(['national_id' => '810000004']);
        $outside = $this->household(['national_id' => '810000005']);
        $this->inBranch($delivered['family'], 'ABU_TEIMA');
        $this->inOtherClan($outside['family']);

        $deliver = fn ($h, $b) => $this->actingAs($this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$b->uuid}/delivery", [
                'receipt_mode' => 'PERSONAL', 'beneficiary_national_id' => $h['head']->national_id,
            ])->assertCreated();

        $b1 = $this->approvedBeneficiary($assistance, $delivered['family']);
        $deliver($delivered, $b1);
        $b2 = $this->approvedBeneficiary($assistance, $reversed['family']);
        $deliver($reversed, $b2);
        $this->actingAs($this->user)->postJson('/api/v1/assistance-deliveries/'.AssistanceDelivery::where('assistance_beneficiary_id', $b2->id)->value('uuid').'/reverse', ['reversal_reason' => 'تصحيح تجريبي'])->assertOk();
        $b3 = $this->approvedBeneficiary($assistance, $notDelivered['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$b3->uuid}/not-delivered", ['not_delivered_reason' => 'لم يحضر'])->assertOk();
        $this->nominate($assistance, $nominated['family']);
        $b5 = $this->approvedBeneficiary($assistance, $outside['family']);
        $deliver($outside, $b5);

        $data = $this->dash()->assertOk()->json('data.assistance');
        $this->assertSame(['internal' => 1, 'external' => 0], $data['open_programs']);
        $this->assertSame([
            'nominated' => 1,
            'approved' => 3,
            'awaiting_delivery' => 1,
            'delivered' => 1,
            'not_delivered' => 1,
        ], $data['internal']);

        $this->dash(['branch' => 'ABU_TEIMA'])->assertOk()
            ->assertJsonPath('data.assistance.internal.delivered', 1)
            ->assertJsonPath('data.assistance.internal.awaiting_delivery', 0);
        // No beneficiary in scope: the program is not counted.
        $this->dash(['branch_group' => 'BG17'])->assertOk()
            ->assertJsonPath('data.assistance.open_programs.internal', 0);
    }

    public function test_external_listing_is_never_delivery_and_counts_unique_beneficiaries(): void
    {
        $assistance = $this->openAssistanceOf('EXTERNAL', ['provider_name' => 'منظمة خارجية تجريبية']);
        $this->actingAs($this->user)->putJson("/api/v1/assistances/{$assistance->uuid}/export-configuration", ['fields' => [
            ['field_key' => 'beneficiary_name', 'column_label' => 'الاسم'],
            ['field_key' => 'national_id', 'column_label' => 'رقم الهوية'],
        ]])->assertOk();

        $a = $this->approvedBeneficiary($assistance, $this->household(['national_id' => '820000001'])['family']);
        $b = $this->approvedBeneficiary($assistance, $this->household(['national_id' => '820000002'])['family']);
        $this->approvedBeneficiary($assistance, $this->household(['national_id' => '820000003'])['family']);
        $issue = fn (array $list) => $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/beneficiary-lists", [
            'beneficiary_ids' => array_map(fn ($x) => $x->uuid, $list),
        ])->assertCreated();
        $issue([$a, $b]);
        // Corrected list: B again — still one unique listed beneficiary.
        $issue([$b]);

        $response = $this->dash()->assertOk();
        $this->assertSame([
            'nominated' => 0,
            'approved' => 3,
            'approved_not_listed' => 1,
            'listed_unique' => 2,
        ], $response->json('data.assistance.external'));
        $this->assertSame(['internal' => 0, 'external' => 1], $response->json('data.assistance.open_programs'));
        $this->assertSame(0, $response->json('data.assistance.internal.delivered'));
        $this->assertArrayNotHasKey('delivered', $response->json('data.assistance.external'));
    }

    // --------------------------------------------------------------- activity

    private function activity(Family $family, FamilyActivityType $type, string $at, array $metadata = []): void
    {
        Carbon::setTestNow($at);
        DB::transaction(fn () => FamilyActivityLog::record($family->id, $type, null, $this->user->id, $metadata));
    }

    public function test_recent_activity_is_scoped_latest_first_and_limited(): void
    {
        $inScope = $this->inBranch($this->family(1), 'ABU_TEIMA');
        $other = $this->family(1);
        for ($i = 1; $i <= 12; $i++) {
            $this->activity($inScope, FamilyActivityType::FAMILY_UPDATED, sprintf('2026-09-%02d 09:00:00', $i));
        }
        $this->activity($other, FamilyActivityType::FAMILY_UPDATED, '2026-09-20 09:00:00');
        $this->activity($this->inOtherClan($this->family(1)), FamilyActivityType::FAMILY_UPDATED, '2026-09-21 09:00:00');
        Carbon::setTestNow('2026-09-24 10:00:00');

        $clan = $this->dash()->assertOk()->json('data.recent_activity');
        $this->assertCount(10, $clan);
        $this->assertSame($other->family_code, $clan[0]['family']['family_code']);
        $this->assertSame($inScope->family_code, $clan[1]['family']['family_code']);
        $this->assertStringStartsWith('2026-09-12', $clan[1]['occurred_at']);

        $branch = $this->dash(['branch' => 'ABU_TEIMA'])->assertOk()->json('data.recent_activity');
        $this->assertCount(10, $branch);
        $this->assertSame([$inScope->family_code], array_values(array_unique(array_column(array_column($branch, 'family'), 'family_code'))));
    }

    // ------------------------------------------------------------ permissions

    public function test_dashboard_roles_and_family_user_denied(): void
    {
        $this->getJson('/api/v1/dashboard?clan=AL_BREEM')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/scope-options')->assertUnauthorized();

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->dash([], $this->user($role))->assertOk();
            $this->actingAs($this->user($role))->getJson('/api/v1/dashboard/scope-options')->assertOk();
        }
        $familyUser = $this->user('FAMILY_USER');
        $this->dash([], $familyUser)->assertForbidden();
        $this->actingAs($familyUser)->getJson('/api/v1/dashboard/scope-options')->assertForbidden();
    }

    public function test_sections_without_domain_permission_are_null_not_hidden(): void
    {
        $family = $this->family(2);
        $this->healthRecord($family->memberships()->first()->person, 'DISABILITY');
        $this->need($family);

        // REPORTS_VIEWER: population only.
        $viewer = $this->dash([], $this->user('REPORTS_VIEWER'))->assertOk();
        $viewer->assertJsonPath('data.kpis.active_families', 1)
            ->assertJsonPath('data.kpis.current_people', 2)
            ->assertJsonPath('data.kpis.open_needs', null);
        foreach (['health', 'needs', 'assessments', 'assistance', 'recent_activity'] as $section) {
            $viewer->assertJsonPath("data.$section", null);
        }
        $this->assertNotNull($viewer->json('data.demographics'));
        $this->assertNotNull($viewer->json('data.displacement'));
    }

    public function test_recent_activity_respects_event_level_visibility(): void
    {
        $family = $this->family(1);
        $this->activity($family, FamilyActivityType::FAMILY_UPDATED, '2026-09-10 09:00:00');
        $this->activity($family, FamilyActivityType::HEALTH_RECORD_CREATED, '2026-09-11 09:00:00', ['health_record_type' => 'DISABILITY']);
        $this->activity($family, FamilyActivityType::NEED_CREATED, '2026-09-12 09:00:00');
        Carbon::setTestNow('2026-09-24 10:00:00');

        // A role with the activity log but no health / need permissions.
        Role::create(['name' => 'TEST_ACTIVITY_ONLY', 'guard_name' => 'web'])
            ->givePermissionTo(['dashboard.view-operational', 'family.view', 'person.view', 'activity-log.view']);
        $limited = $this->user('TEST_ACTIVITY_ONLY');

        $response = $this->dash([], $limited)->assertOk();
        $this->assertSame(['FAMILY_UPDATED'], array_column($response->json('data.recent_activity'), 'event_type'));
        $response->assertJsonPath('data.health', null)->assertJsonPath('data.needs', null);

        $this->assertSame(
            ['NEED_CREATED', 'HEALTH_RECORD_CREATED', 'FAMILY_UPDATED'],
            array_column($this->dash()->assertOk()->json('data.recent_activity'), 'event_type'),
        );
    }

    // ---------------------------------------------------------------- privacy

    public function test_response_contains_no_identity_health_need_or_snapshot_details(): void
    {
        $assistance = $this->openAssistanceOf('EXTERNAL');
        $this->actingAs($this->user)->putJson("/api/v1/assistances/{$assistance->uuid}/export-configuration", ['fields' => [
            ['field_key' => 'national_id', 'column_label' => 'رقم الهوية'],
            ['field_key' => 'primary_mobile', 'column_label' => 'الجوال'],
        ]])->assertOk();
        $h = $this->household(['national_id' => '830000001', 'mobile' => '0591234567']);
        $b = $this->approvedBeneficiary($assistance, $h['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/beneficiary-lists", ['beneficiary_ids' => [$b->uuid]])->assertCreated();
        $this->reject($assistance, $this->nominate($assistance, $this->household(['national_id' => '830000002'])['family']), 'سبب رفض سري تجريبي')->assertOk();

        $this->healthRecord($h['head'], 'CHRONIC_DISEASE', ['condition_name' => 'مرض سري تجريبي', 'details' => 'تفاصيل صحية سرية']);
        $this->need($h['family'], ['title' => 'حاجة تجريبية', 'description' => 'وصف حاجة سري']);
        $this->assessment($h['family'], 'SHELTER', 'HIGH', '2026-09-01', notes: 'ملاحظة تقييم سرية');

        $content = $this->dash()->assertOk()->getContent();

        foreach (['830000001', '830000002', '0591234567', 'مرض سري تجريبي', 'تفاصيل صحية سرية', 'وصف حاجة سري', 'ملاحظة تقييم سرية', 'سبب رفض سري تجريبي', 'national_id', 'mobile'] as $secret) {
            $this->assertStringNotContainsString($secret, $content, $secret);
        }
        $this->assertStringNotContainsString(json_encode('مرض سري تجريبي'), $content);
    }

    public function test_metrics_are_derived_not_stored(): void
    {
        $family = $this->family(2);
        $this->dash()->assertOk()->assertJsonPath('data.kpis.current_people', 2);

        $this->member($family, []);

        // No cache/snapshot: the next request reflects canonical data.
        $this->dash()->assertOk()->assertJsonPath('data.kpis.current_people', 3);
    }
}
