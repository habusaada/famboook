<?php

namespace Tests\Feature\Assistances;

use App\Enums\NeedStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceBeneficiaryList;
use App\Models\AssistanceBeneficiaryListEntry;
use App\Models\AssistanceDelivery;
use App\Models\FamilyActivity;
use App\Models\FamilyResidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use ZipArchive;

/**
 * EXTERNAL execution: requested export fields, dynamic preview, immutable
 * issued lists with snapshots, private XLSX, statistics and completion
 * (docs/03-BUSINESS-RULES.md §47g–§47h). All values are synthetic.
 */
class AssistanceExternalListTest extends TestCase
{
    use BuildsExecutionFixtures, RefreshDatabase;

    private Assistance $assistance;

    private const FIELDS = [
        ['field_key' => 'beneficiary_name', 'column_label' => 'اسم المستفيد'],
        ['field_key' => 'national_id', 'column_label' => 'رقم الهوية'],
        ['field_key' => 'primary_mobile', 'column_label' => 'رقم الجوال'],
        ['field_key' => 'family_members_count', 'column_label' => 'عدد أفراد الأسرة'],
        ['field_key' => 'displacement_location', 'column_label' => 'مكان النزوح'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExecutionFixtures();
        $this->assistance = $this->openAssistanceOf('EXTERNAL', ['target_beneficiaries' => 10, 'provider_name' => 'منظمة خارجية تجريبية']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configure(array $fields = self::FIELDS, ?User $as = null, ?Assistance $assistance = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->putJson('/api/v1/assistances/'.($assistance ?? $this->assistance)->uuid.'/export-configuration', ['fields' => $fields]);
    }

    private function preview(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/beneficiary-lists/preview");
    }

    private function issue(array $beneficiaries, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/beneficiary-lists", [
            'beneficiary_ids' => array_map(fn ($b) => $b->uuid, $beneficiaries),
            ...$extra,
        ]);
    }

    /** Two approved beneficiaries: a family-level one and a person-level one. */
    private function approvedPair(): array
    {
        $a = $this->household(['full_name' => 'رب أسرة أ', 'national_id' => '900000001', 'mobile' => '0590000011'], [
            'wife' => ['SPOUSE', ['full_name' => 'زوجة أ']],
            'kid' => ['SON', ['full_name' => 'ابن أ', 'gender' => 'MALE', 'birth_date' => '2025-06-01']],
        ]);
        $b = $this->household(['full_name' => 'رب أسرة ب', 'national_id' => '900000002'], [
            'daughter' => ['DAUGHTER', ['full_name' => 'ابنة ب', 'national_id' => '900000003', 'mobile' => '0590000033']],
        ]);

        return [
            $this->approvedBeneficiary($this->assistance, $a['family']),
            $this->approvedBeneficiary($this->assistance, $b['family'], $b['people']['daughter']),
            $a,
            $b,
        ];
    }

    private function xlsxRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('rightToLeft="1"', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($path);

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $cells[] = isset($c->is) ? (string) $c->is->t : (isset($c->v) ? (int) $c->v : null);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    // ---------------------------------------------------------------- configuration

    public function test_configuration_requires_external_mode(): void
    {
        $internal = $this->openAssistanceOf('INTERNAL', ['title' => 'داخلي']);
        $this->configure(self::FIELDS, null, $internal)->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$internal->uuid}/beneficiary-lists/preview")->assertStatus(409);
    }

    public function test_supported_fields_order_and_labels_are_saved(): void
    {
        $this->configure([
            ['field_key' => 'primary_mobile', 'column_label' => 'جوال'],
            ['field_key' => 'beneficiary_name', 'column_label' => 'الاسم الرباعي'],
        ])->assertOk()
            ->assertJsonPath('data.configuration.0.field_key', 'primary_mobile')
            ->assertJsonPath('data.configuration.0.column_label', 'جوال')
            ->assertJsonPath('data.configuration.0.sort_order', 1)
            ->assertJsonPath('data.configuration.1.field_key', 'beneficiary_name')
            ->assertJsonPath('data.contains_sensitive', false);

        $catalog = collect($this->actingAs($this->user)->getJson("/api/v1/assistances/{$this->assistance->uuid}/export-fields")->json('data.catalog'))->keyBy('field_key');
        $this->assertSame('SENSITIVE', $catalog['national_id']['classification']);
        $this->assertSame('CONTACT', $catalog['primary_mobile']['classification']);
        $this->assertSame('STANDARD', $catalog['family_members_count']['classification']);
        $this->assertSame('SENSITIVE', $catalog['has_disability']['classification']);
    }

    public function test_unsupported_and_duplicate_fields_are_rejected(): void
    {
        $this->configure([['field_key' => 'password', 'column_label' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('fields.0.field_key');
        $this->configure([['field_key' => 'persons.national_id', 'column_label' => 'x']])->assertUnprocessable();
        $this->configure([
            ['field_key' => 'family_code', 'column_label' => 'أ'],
            ['field_key' => 'family_code', 'column_label' => 'ب'],
        ])->assertUnprocessable()->assertJsonValidationErrors('fields.1.field_key');
        $this->configure([['field_key' => 'family_code', 'column_label' => '']])->assertUnprocessable()->assertJsonValidationErrors('fields.0.column_label');
        $this->assertNull($this->assistance->fresh()->export_fields);
    }

    public function test_sensitive_configuration_requires_sensitive_permission(): void
    {
        // DATA_ENTRY has no export permissions at all.
        $this->configure(self::FIELDS, $this->user('DATA_ENTRY'))->assertForbidden();

        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['assistance.view', 'assistance.export']);
        $this->configure([['field_key' => 'national_id', 'column_label' => 'هوية']], $exporter)->assertForbidden();
        $this->configure([['field_key' => 'beneficiary_name', 'column_label' => 'اسم']], $exporter)->assertOk();

        $this->configure(self::FIELDS)->assertOk()->assertJsonPath('data.contains_sensitive', true);
    }

    public function test_targeting_and_export_configuration_are_independent(): void
    {
        $draft = Assistance::where('uuid', $this->createAssistance(['execution_mode' => 'EXTERNAL', 'title' => 'مسودة'])->json('data.id'))->first();
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$draft->uuid}", ['targeting_criteria' => ['min_family_members' => 5]])->assertOk();
        $this->configure(self::FIELDS, null, $draft)->assertOk();

        $draft->refresh();
        $this->assertSame(['min_family_members' => 5], $draft->targeting_criteria);
        $this->assertCount(5, $draft->export_fields);

        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$draft->uuid}", ['targeting_criteria' => ['displacement_status' => 'DISPLACED']])->assertOk();
        $this->assertCount(5, $draft->fresh()->export_fields);
    }

    // ---------------------------------------------------------------- preview

    public function test_preview_includes_only_approved_with_configured_columns(): void
    {
        [$family, $person, $a] = $this->approvedPair();
        $this->nominate($this->assistance, $this->household()['family']);
        $this->reject($this->assistance, $this->nominate($this->assistance, $this->household()['family']))->assertOk();
        $removed = $this->nominate($this->assistance, $this->household()['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/{$removed->uuid}/remove")->assertOk();
        $this->configure()->assertOk();

        $data = $this->preview()->assertOk()->json('data');

        $this->assertSame(2, $data['row_count']);
        $this->assertTrue($data['contains_sensitive']);
        $this->assertSame(['اسم المستفيد', 'رقم الهوية', 'رقم الجوال', 'عدد أفراد الأسرة', 'مكان النزوح'], array_column($data['columns'], 'column_label'));
        $this->assertSame(['beneficiary_name', 'national_id', 'primary_mobile', 'family_members_count', 'displacement_location'], array_keys($data['rows'][0]['values']));
        $this->assertEqualsCanonicalizing([$family->uuid, $person->uuid], array_column($data['rows'], 'beneficiary_id'));
    }

    public function test_field_semantics_for_family_and_person_beneficiaries(): void
    {
        [$family, $person] = $this->approvedPair();
        $this->configure([
            ...self::FIELDS,
            ['field_key' => 'household_head_name', 'column_label' => 'رب الأسرة'],
            ['field_key' => 'children_under_2_count', 'column_label' => 'أطفال دون سنتين'],
            ['field_key' => 'marital_status', 'column_label' => 'الحالة الاجتماعية'],
        ])->assertOk();

        $rows = collect($this->preview()->json('data.rows'))->keyBy('beneficiary_id');
        $f = $rows[$family->uuid]['values'];
        $p = $rows[$person->uuid]['values'];

        // Family-level: the current household head.
        $this->assertSame('رب أسرة أ', $f['beneficiary_name']);
        $this->assertSame('900000001', $f['national_id']);
        $this->assertSame('0590000011', $f['primary_mobile']);
        $this->assertSame(3, $f['family_members_count']);
        $this->assertSame(1, $f['children_under_2_count']);
        $this->assertSame('مخيم تجريبي', $f['displacement_location']);
        $this->assertSame('متزوج/ة', $f['marital_status']);
        // Person-level: the nominated person; family fields describe their family.
        $this->assertSame('ابنة ب', $p['beneficiary_name']);
        $this->assertSame('900000003', $p['national_id']);
        $this->assertSame('0590000033', $p['primary_mobile']);
        $this->assertSame('رب أسرة ب', $p['household_head_name']);
        $this->assertSame(2, $p['family_members_count']);
        $this->assertSame('أعزب/عزباء', $p['marital_status']);
    }

    public function test_preview_authorization_and_no_side_effects(): void
    {
        $this->approvedPair();
        $this->configure()->assertOk();
        $activity = FamilyActivity::count();

        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['assistance.view', 'assistance.export']);
        $this->preview($exporter)->assertForbidden();
        $this->preview($this->user('SOCIAL_WORKER'))->assertForbidden();
        $this->preview()->assertOk();

        $this->assertSame(0, AssistanceBeneficiaryList::count());
        $this->assertSame(0, AssistanceBeneficiaryListEntry::count());
        $this->assertSame($activity, FamilyActivity::count());
    }

    // ---------------------------------------------------------------- issuance

    public function test_issue_creates_immutable_list_with_snapshot(): void
    {
        [$family, $person] = $this->approvedPair();
        $this->configure()->assertOk();

        Carbon::setTestNow('2026-09-24 15:00:00');
        $response = $this->issue([$family, $person], ['notes' => 'الدفعة الأولى'])->assertCreated()
            ->assertJsonPath('data.recipient_organization', 'منظمة خارجية تجريبية')
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.contains_sensitive', true)
            ->assertJsonPath('data.issued_by.name', 'مدير تجريبي')
            ->assertJsonPath('data.columns.1.column_label', 'رقم الهوية');
        $this->assertMatchesRegularExpression('/^ABL-\d{6}$/', $response->json('data.list_number'));
        // Metadata never carries issued values.
        $this->assertStringNotContainsString('900000001', $response->getContent());

        $list = AssistanceBeneficiaryList::first();
        $this->assertSame('2026-09-24 15:00:00', $list->issued_at->toDateTimeString());
        $this->assertSame(['field_key' => 'beneficiary_name', 'column_label' => 'اسم المستفيد', 'classification' => 'STANDARD'], $list->configuration_snapshot[0]);
        $entry = $list->entries()->first();
        $this->assertSame(1, $entry->row_number);
        $this->assertSame('900000001', $entry->snapshot_data['national_id']);
        // Encrypted at rest.
        $this->assertStringNotContainsString('900000001', DB::table('assistance_beneficiary_list_entries')->value('snapshot_data'));

        // Issuance is not delivery.
        $this->assertSame(0, AssistanceDelivery::count());
        $this->assertSame('APPROVED', $family->fresh()->status->value);

        $this->expectException(LogicException::class);
        $list->update(['recipient_organization' => 'x']);
    }

    public function test_entries_cannot_be_changed_or_deleted(): void
    {
        [$family] = $this->approvedPair();
        $this->configure()->assertOk();
        $this->issue([$family])->assertCreated();

        $this->expectException(LogicException::class);
        AssistanceBeneficiaryListEntry::first()->delete();
    }

    public function test_issue_rejects_empty_and_non_approved(): void
    {
        [$family] = $this->approvedPair();
        $nominated = $this->nominate($this->assistance, $this->household()['family']);
        $this->configure()->assertOk();

        $this->issue([])->assertUnprocessable()->assertJsonValidationErrors('beneficiary_ids');
        $this->issue([$family, $nominated])->assertUnprocessable()->assertJsonValidationErrors('beneficiary_ids.1');
        $this->assertSame(0, AssistanceBeneficiaryList::count());
    }

    public function test_issue_requires_configuration_and_permissions(): void
    {
        [$family] = $this->approvedPair();
        $this->issue([$family])->assertUnprocessable()->assertJsonValidationErrors('fields');

        $this->configure()->assertOk();
        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['assistance.view', 'assistance.export']);
        $this->issue([$family], [], $exporter)->assertForbidden();
        $this->issue([$family], [], $this->user('SOCIAL_WORKER'))->assertForbidden();
        $this->issue([$family], ['recipient_organization' => 'جهة مستلمة أخرى'])->assertCreated()
            ->assertJsonPath('data.recipient_organization', 'جهة مستلمة أخرى');
    }

    public function test_snapshot_survives_person_and_family_changes_and_corrected_list(): void
    {
        [$family, $person, $a] = $this->approvedPair();
        $this->configure()->assertOk();
        $first = $this->issue([$family, $person])->assertCreated()->json('data.id');

        $a['head']->update(['full_name' => 'اسم معدل', 'mobile' => '0599999999']);
        FamilyResidence::where('family_id', $a['family']->id)->update(['displacement_location_text' => 'مكان جديد']);

        $old = $this->actingAs($this->user)->getJson("/api/v1/assistance-beneficiary-lists/{$first}")->assertOk();
        $this->assertSame('رب أسرة أ', $old->json('data.rows.0.values.beneficiary_name'));
        $this->assertSame('0590000011', $old->json('data.rows.0.values.primary_mobile'));
        $this->assertSame('مخيم تجريبي', $old->json('data.rows.0.values.displacement_location'));

        // A corrected list is a NEW list; the first one stays as issued.
        $second = $this->issue([$family])->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertSame('اسم معدل', $this->actingAs($this->user)->getJson("/api/v1/assistance-beneficiary-lists/{$second}")->json('data.rows.0.values.beneficiary_name'));
        $this->assertSame('رب أسرة أ', $this->actingAs($this->user)->getJson("/api/v1/assistance-beneficiary-lists/{$first}")->json('data.rows.0.values.beneficiary_name'));

        $lists = $this->actingAs($this->user)->getJson("/api/v1/assistances/{$this->assistance->uuid}/beneficiary-lists")->assertOk()->json('data');
        $this->assertCount(2, $lists);

        // Preview shows earlier list membership.
        $row = collect($this->preview()->json('data.rows'))->firstWhere('beneficiary_id', $family->uuid);
        $this->assertCount(2, $row['listed_in']);
    }

    public function test_issuance_leaves_needs_and_records_listed_activity(): void
    {
        $h = $this->household();
        $need = $this->need($h['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/from-needs", ['need_ids' => [$need->uuid]])->assertOk();
        $b = AssistanceBeneficiary::first();
        $this->approve($this->assistance, $b)->assertOk();
        $this->configure()->assertOk();
        $this->issue([$b])->assertCreated();

        $this->assertSame(NeedStatus::OPEN, $need->fresh()->status);
        $this->assertTrue(FamilyActivity::where('family_id', $h['family']->id)->where('event_type', 'ASSISTANCE_BENEFICIARY_LISTED')->exists());
        $this->assertSame(0, FamilyActivity::whereNotNull('metadata')->count());
        $raw = $this->actingAs($this->user)->getJson("/api/v1/families/{$h['family']->family_code}/activities")->getContent();
        $this->assertStringNotContainsString('800000001', $raw);
    }

    // ---------------------------------------------------------------- export security

    public function test_snapshot_and_xlsx_require_authorization(): void
    {
        [$family] = $this->approvedPair();
        $this->configure()->assertOk();
        $list = $this->issue([$family])->assertCreated()->json('data.id');

        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['assistance.view', 'assistance.export']);
        foreach ([$exporter, $this->user('REVIEWER'), $this->user('SOCIAL_WORKER'), $this->user('REPORTS_VIEWER')] as $user) {
            $this->actingAs($user)->getJson("/api/v1/assistance-beneficiary-lists/{$list}")->assertForbidden();
            $this->actingAs($user)->get("/api/v1/assistance-beneficiary-lists/{$list}/download")->assertForbidden();
        }
        // Metadata history is visible to viewers.
        $this->actingAs($this->user('REVIEWER'))->getJson("/api/v1/assistances/{$this->assistance->uuid}/beneficiary-lists")->assertOk();
    }

    public function test_non_sensitive_list_is_available_with_normal_export_permission(): void
    {
        [$family] = $this->approvedPair();
        $this->configure([['field_key' => 'beneficiary_name', 'column_label' => 'اسم'], ['field_key' => 'family_code', 'column_label' => 'رقم الأسرة']])->assertOk();
        $list = $this->issue([$family])->assertCreated()->assertJsonPath('data.contains_sensitive', false)->json('data.id');

        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['assistance.view', 'assistance.export']);
        $this->actingAs($exporter)->getJson("/api/v1/assistance-beneficiary-lists/{$list}")->assertOk();
        $this->actingAs($exporter)->get("/api/v1/assistance-beneficiary-lists/{$list}/download")->assertOk();
    }

    public function test_xlsx_has_exactly_the_configured_columns_and_snapshot_values(): void
    {
        [$family, $person, $a] = $this->approvedPair();
        $this->configure()->assertOk();
        $previewRows = collect($this->preview()->json('data.rows'))->keyBy('beneficiary_id');
        $listId = $this->issue([$family, $person])->assertCreated()->json('data.id');
        $list = AssistanceBeneficiaryList::where('uuid', $listId)->first();

        // Live data changes must not affect the download.
        $a['head']->update(['full_name' => 'اسم بعد الإصدار']);

        $response = $this->actingAs($this->user)->get("/api/v1/assistance-beneficiary-lists/{$listId}/download")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertSame('attachment; filename="'.$list->list_number.'-2026-09-24.xlsx"', $disposition);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['900000001', '0590000011', 'رب'] as $secret) {
            $this->assertStringNotContainsString($secret, $disposition);
        }

        $rows = $this->xlsxRows($response->getContent());
        $this->assertCount(3, $rows);
        $this->assertSame(['اسم المستفيد', 'رقم الهوية', 'رقم الجوال', 'عدد أفراد الأسرة', 'مكان النزوح'], $rows[0]);
        $this->assertSame(array_values($previewRows[$family->uuid]['values']), $rows[1]);
        $this->assertSame(array_values($previewRows[$person->uuid]['values']), $rows[2]);
        $this->assertSame('رب أسرة أ', $rows[1][0]);
        // No hidden ids/uuids anywhere in the sheet.
        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($family->uuid, $flat);
        $this->assertStringNotContainsString($list->uuid, $flat);
    }

    public function test_general_resources_never_expose_snapshot_or_national_ids(): void
    {
        [$family, $person, $a, $b] = $this->approvedPair();
        $this->configure()->assertOk();
        $this->issue([$family, $person])->assertCreated();

        foreach ([
            "/api/v1/assistances/{$this->assistance->uuid}",
            '/api/v1/assistances',
            "/api/v1/assistances/{$this->assistance->uuid}/nominees",
            "/api/v1/assistances/{$this->assistance->uuid}/beneficiary-lists",
            "/api/v1/families/{$a['family']->family_code}/assistances",
            "/api/v1/families/{$a['family']->family_code}/activities",
            "/api/v1/families/{$b['family']->family_code}",
        ] as $url) {
            $raw = $this->actingAs($this->user)->getJson($url)->assertOk()->getContent();
            foreach (['900000001', '900000003', '0590000011', '0590000033', 'snapshot_data'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "{$url} / {$secret}");
            }
        }

        // The Person resource shows phones to person.view holders, but never National IDs.
        $raw = $this->actingAs($this->user)->getJson("/api/v1/people/{$b['people']['daughter']->person_code}")->assertOk()->getContent();
        $this->assertStringNotContainsString('900000003', $raw);
    }

    // ---------------------------------------------------------------- statistics & completion

    public function test_external_statistics_never_report_delivery(): void
    {
        [$family, $person] = $this->approvedPair();
        $third = $this->approvedBeneficiary($this->assistance, $this->household()['family']);
        $this->reject($this->assistance, $this->nominate($this->assistance, $this->household()['family']))->assertOk();
        $this->nominate($this->assistance, $this->household()['family']);
        $this->configure()->assertOk();
        $this->issue([$family, $person])->assertCreated();
        // Corrected list containing the family again: unique count unchanged.
        $this->issue([$family])->assertCreated();

        $stats = $this->statistics($this->assistance);
        $this->assertSame(10, $stats['target']);
        $this->assertSame(5, $stats['total_nominees']);
        $this->assertSame(1, $stats['pending_approval']);
        $this->assertSame(3, $stats['approved']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, $stats['approved_not_listed']);
        $this->assertSame(2, $stats['listed_unique']);
        $this->assertSame(2, $stats['issued_lists']);
        $this->assertSame('UNKNOWN', $stats['external_execution_result']);
        $this->assertArrayNotHasKey('delivered', $stats);
        $this->assertArrayNotHasKey('awaiting_delivery', $stats);

        $history = collect($this->actingAs($this->user)->getJson('/api/v1/families/'.$family->family->family_code.'/assistances')->json('data'))->first();
        $this->assertCount(2, $history['lists']);
        $this->assertNull($history['delivery']);
    }

    public function test_external_completion_rules(): void
    {
        [$family, $person] = $this->approvedPair();
        $pending = $this->nominate($this->assistance, $this->household()['family']);
        $this->configure()->assertOk();

        $this->complete($this->assistance)->assertUnprocessable();
        $this->reject($this->assistance, $pending)->assertOk();
        // Approved but never listed blocks.
        $this->issue([$family])->assertCreated();
        $this->complete($this->assistance)->assertUnprocessable();

        $this->issue([$person])->assertCreated();
        $removed = $this->nominate($this->assistance, $this->household()['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/{$removed->uuid}/remove")->assertOk();

        $this->complete($this->assistance)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        // Completion never implies delivery.
        $this->assertSame(0, AssistanceDelivery::count());
        $this->assertArrayNotHasKey('delivered', $this->statistics($this->assistance->fresh()));
        $this->issue([$family])->assertStatus(409);
    }
}
