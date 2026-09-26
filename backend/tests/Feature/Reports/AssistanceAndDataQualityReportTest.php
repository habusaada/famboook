<?php

namespace Tests\Feature\Reports;

use App\Models\Assistance;
use App\Models\AssistanceDelivery;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports V1 — Assistance (scoped program statistics, INTERNAL vs
 * EXTERNAL) and Data Quality (completeness, consistency, drill-down).
 */
class AssistanceAndDataQualityReportTest extends TestCase
{
    use BuildsReportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportFixtures();
    }

    // ------------------------------------------------------------- assistance

    private function deliver(Assistance $assistance, array $household, $beneficiary): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$beneficiary->uuid}/delivery", [
                'receipt_mode' => 'PERSONAL', 'beneficiary_national_id' => $household['head']->national_id,
            ])->assertCreated();
    }

    /** INTERNAL program: delivered (BG07), reversed, not delivered, nominated, rejected, other Clan delivered. */
    private function internalProgram(): Assistance
    {
        $assistance = $this->openAssistanceOf('INTERNAL', ['title' => 'برنامج داخلي تجريبي', 'target_beneficiaries' => 50]);
        $delivered = $this->household(['national_id' => '840000001']);
        $this->inBranch($delivered['family'], 'ABU_TEIMA');
        $reversed = $this->household(['national_id' => '840000002']);
        $notDelivered = $this->household(['national_id' => '840000003']);
        $outside = $this->household(['national_id' => '840000004']);
        $this->inOtherClan($outside['family']);

        $this->deliver($assistance, $delivered, $this->approvedBeneficiary($assistance, $delivered['family']));
        $b2 = $this->approvedBeneficiary($assistance, $reversed['family']);
        $this->deliver($assistance, $reversed, $b2);
        $this->actingAs($this->user)->postJson('/api/v1/assistance-deliveries/'.AssistanceDelivery::where('assistance_beneficiary_id', $b2->id)->value('uuid').'/reverse', ['reversal_reason' => 'سبب عكس سري'])->assertOk();
        $b3 = $this->approvedBeneficiary($assistance, $notDelivered['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$b3->uuid}/not-delivered", ['not_delivered_reason' => 'سبب عدم تسليم سري'])->assertOk();
        $this->nominate($assistance, $this->household(['national_id' => '840000005'])['family']);
        $this->reject($assistance, $this->nominate($assistance, $this->household(['national_id' => '840000006'])['family']), 'سبب رفض سري')->assertOk();
        $this->deliver($assistance, $outside, $this->approvedBeneficiary($assistance, $outside['family']));

        return $assistance;
    }

    public function test_internal_program_statistics_are_scoped(): void
    {
        $assistance = $this->internalProgram();

        $response = $this->report('assistance')->assertOk();
        $row = $response->json('data.rows.data.0');
        $this->assertSame($assistance->uuid, $row['id']);
        $this->assertSame(['INTERNAL', 'OPEN', 50], [$row['execution_mode'], $row['status'], $row['target']]);
        $this->assertSame([1, 3, 1], [$row['nominated'], $row['approved'], $row['rejected']]);
        $this->assertSame([
            'awaiting_delivery' => 1,
            'delivered' => 1,
            'not_delivered' => 1,
            'reversed_deliveries' => 1,
            'delivery_percentage' => 33.3,
        ], $row['internal']);
        $this->assertNull($row['external']);
        $response->assertJsonPath('data.totals.internal.delivered', 1)->assertJsonPath('data.totals.internal.programs', 1);

        // Branch scope: only that family's beneficiary counts.
        $branch = $this->report('assistance', ['branch' => 'ABU_TEIMA'])->assertOk()->json('data.rows.data.0');
        $this->assertSame([0, 1, 0], [$row['nominated'] - 1, $branch['approved'], $branch['rejected']]);
        $this->assertSame(1, $branch['internal']['delivered']);
        $this->assertSame(0, $branch['internal']['reversed_deliveries']);
        // No beneficiary in scope: the program is not listed.
        $this->report('assistance', ['branch_group' => 'BG17'])->assertOk()->assertJsonPath('data.rows.meta.total', 0);

        foreach (['840000001', 'سبب رفض سري', 'سبب عكس سري', 'سبب عدم تسليم سري', 'national_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_status_filter_covers_open_and_completed_by_default(): void
    {
        $open = $this->internalProgram();
        $completed = $this->openAssistanceOf('INTERNAL', ['title' => 'برنامج مكتمل تجريبي']);
        $this->approvedBeneficiary($completed, $this->household(['national_id' => '841000001'])['family']);
        $completed->update(['status' => 'COMPLETED']);

        $this->assertEqualsCanonicalizing([$open->uuid, $completed->uuid], array_column($this->report('assistance')->json('data.rows.data'), 'id'));
        $this->assertSame([$completed->uuid], array_column($this->report('assistance', ['status' => 'COMPLETED'])->json('data.rows.data'), 'id'));
        $this->report('assistance', ['status' => 'FINISHED'])->assertStatus(422);
    }

    public function test_external_listing_is_never_delivery_and_unique_across_lists(): void
    {
        $assistance = $this->openAssistanceOf('EXTERNAL', ['title' => 'كشف خارجي تجريبي', 'provider_name' => 'منظمة خارجية تجريبية']);
        $this->actingAs($this->user)->putJson("/api/v1/assistances/{$assistance->uuid}/export-configuration", ['fields' => [
            ['field_key' => 'national_id', 'column_label' => 'رقم الهوية'],
        ]])->assertOk();
        $a = $this->approvedBeneficiary($assistance, $this->household(['national_id' => '850000001'])['family']);
        $b = $this->approvedBeneficiary($assistance, $this->household(['national_id' => '850000002'])['family']);
        $this->approvedBeneficiary($assistance, $this->household(['national_id' => '850000003'])['family']);
        $issue = fn (array $list) => $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/beneficiary-lists", ['beneficiary_ids' => array_map(fn ($x) => $x->uuid, $list)])->assertCreated();
        $issue([$a, $b]);
        $issue([$b]);

        $response = $this->report('assistance')->assertOk();
        $row = $response->json('data.rows.data.0');
        $this->assertNull($row['internal']);
        $this->assertSame(['approved_not_listed' => 1, 'listed_unique' => 2, 'issued_lists' => 2], $row['external']);
        $this->assertSame(3, $row['approved']);
        $response->assertJsonPath('data.totals.external.listed_unique', 2);
        $this->assertArrayNotHasKey('delivered', $response->json('data.totals.external'));
        $this->assertStringNotContainsString('850000001', $response->getContent());
    }

    // ----------------------------------------------------------- data quality

    public function test_completeness_and_consistency_counts_and_drill_down(): void
    {
        $assigned = $this->inBranch($this->family(1), 'ABU_TEIMA');
        $unassigned = $this->family(1, ['name' => 'رب بلا فرع']);
        // Person-level gaps (current population only).
        $noId = $this->addMember($assigned, ['national_id' => null, 'full_name' => 'فرد بلا هوية']);
        $withId = $this->addMember($assigned, ['national_id' => '860000001', 'birth_date' => null, 'mobile' => '0590000099', 'marital_status' => 'SINGLE']);
        $this->addMember($assigned, ['national_id' => '860000002', 'gender' => null, 'mobile' => '0590000098', 'marital_status' => 'MARRIED']);
        $this->addMember($assigned, ['national_id' => null, 'life_status' => 'DECEASED', 'death_date' => '2026-01-01']);  // not current
        // Family-level gaps.
        $displacedNoLocation = $this->family(1, ['displacement' => 'DISPLACED']);
        $noResidence = $this->family(1);
        FamilyResidence::where('family_id', $noResidence->id)->delete();
        $noHead = $this->family(1);
        FamilyMembership::where('family_id', $noHead->id)->update(['is_household_head' => false]);
        $deceasedHead = $this->family(1);
        Person::whereKey($deceasedHead->memberships()->first()->person_id)->update(['life_status' => 'DECEASED', 'death_date' => '2026-01-01']);
        $this->inOtherClan($this->family(1));

        $issues = collect($this->report('data-quality')->assertOk()->json('data.issues'))->keyBy('code');

        // family() fixtures: 1990-01-01, FEMALE, no National ID / mobile, marital UNKNOWN.
        $this->assertSame(5, $issues['FAMILY_WITHOUT_BRANCH']['count']);
        $this->assertSame(1, $issues['FAMILY_WITHOUT_CURRENT_RESIDENCE']['count']);
        $this->assertSame(1, $issues['DISPLACED_WITHOUT_LOCATION']['count']);
        $this->assertSame(1, $issues['ACTIVE_FAMILY_WITHOUT_HEAD']['count']);
        $this->assertSame(1, $issues['HOUSEHOLD_HEAD_DECEASED']['count']);
        // Current people: 6 fixture members (deceased head excluded → 5) + 3 added living.
        $this->assertSame(6, $issues['PERSON_MISSING_NATIONAL_ID']['count']);
        $this->assertSame(1, $issues['PERSON_MISSING_BIRTH_DATE']['count']);
        $this->assertSame(1, $issues['PERSON_UNKNOWN_GENDER']['count']);
        $this->assertSame(6, $issues['PERSON_MISSING_MOBILE']['count']);
        $this->assertSame(6, $issues['PERSON_MARITAL_STATUS_UNKNOWN']['count']);
        $this->assertSame('CONSISTENCY', $issues['HOUSEHOLD_HEAD_DECEASED']['group']);
        $this->assertSame('PERSON', $issues['PERSON_MISSING_NATIONAL_ID']['entity']);

        // Drill-down: exact records, identifying fields only.
        $branchRows = $this->report('data-quality/records', ['issue' => 'FAMILY_WITHOUT_BRANCH'])->assertOk()->json('data.rows.data');
        $this->assertCount(5, $branchRows);
        $this->assertNotContains($assigned->family_code, array_column($branchRows, 'family_code'));
        $this->assertSame(['family_code', 'household_head', 'clan', 'branch'], array_keys($branchRows[0]));
        $this->assertContains('رب بلا فرع 0', array_column($branchRows, 'household_head'));
        $this->assertNotNull($unassigned);

        $dob = $this->report('data-quality/records', ['issue' => 'PERSON_MISSING_BIRTH_DATE'])->assertOk()->json('data.rows.data');
        $this->assertSame([['person_code' => $withId->person_code, 'full_name' => $withId->full_name, 'family_code' => $assigned->family_code, 'branch' => 'أبو تيمة']], $dob);

        $noIdResponse = $this->report('data-quality/records', ['issue' => 'PERSON_MISSING_NATIONAL_ID', 'branch' => 'ABU_TEIMA'])->assertOk();
        // Scope preserved: only the ABU_TEIMA family's head and $noId.
        $this->assertEqualsCanonicalizing(
            [$assigned->memberships()->where('is_household_head', true)->first()->person->person_code, $noId->person_code],
            array_column($noIdResponse->json('data.rows.data'), 'person_code'),
        );
        foreach (['860000001', '860000002', '0590000099', 'national_id', 'mobile'] as $secret) {
            $this->assertStringNotContainsString($secret, $noIdResponse->getContent());
        }

        $this->assertSame([$noHead->family_code], array_column($this->report('data-quality/records', ['issue' => 'ACTIVE_FAMILY_WITHOUT_HEAD'])->json('data.rows.data'), 'family_code'));
        $this->assertSame([$deceasedHead->family_code], array_column($this->report('data-quality/records', ['issue' => 'HOUSEHOLD_HEAD_DECEASED'])->json('data.rows.data'), 'family_code'));
        $this->assertSame([$displacedNoLocation->family_code], array_column($this->report('data-quality/records', ['issue' => 'DISPLACED_WITHOUT_LOCATION'])->json('data.rows.data'), 'family_code'));

        // At group scope every family has a Branch.
        $this->report('data-quality', ['branch_group' => 'BG07'])->assertOk()->assertJsonPath('data.issues.0.count', 0);

        $this->report('data-quality/records', [])->assertStatus(422);
        $this->report('data-quality/records', ['issue' => 'LIKELY_DUPLICATE'])->assertUnprocessable();
        $this->report('data-quality/records', ['issue' => 'PERSON_MISSING_MOBILE', 'per_page' => 2])->assertOk()
            ->assertJsonCount(2, 'data.rows.data')->assertJsonPath('data.rows.meta.total', 6);
    }
}
