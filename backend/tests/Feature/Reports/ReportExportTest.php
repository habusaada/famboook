<?php

namespace Tests\Feature\Reports;

use App\Models\Assistance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports V1 XLSX exports: authenticated, permission-checked, private,
 * exact Arabic columns, RTL sheets, the exact scope and filters, and no
 * sensitive or internal values (docs/03 §55b, docs/06 §59b).
 */
class ReportExportTest extends TestCase
{
    use BuildsReportFixtures;
    use RefreshDatabase;

    private const SECRETS = [
        '870000001', '0591112223', 'مرض سري تجريبي', 'تفاصيل صحية سرية', 'وصف حاجة سري',
        'ملاحظة تقييم سرية', 'سبب رفض سري', 'سبب إغلاق سري',
    ];

    private Assistance $external;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportFixtures();

        // One household with every kind of sensitive value.
        $h = $this->household(['national_id' => '870000001', 'mobile' => '0591112223', 'full_name' => 'رب أسرة التصدير']);
        $this->inBranch($h['family'], 'ABU_TEIMA');
        $this->healthRecord($h['head'], 'CHRONIC_DISEASE', ['condition_name' => 'مرض سري تجريبي', 'details' => 'تفاصيل صحية سرية']);
        $this->need($h['family'], ['title' => 'حاجة تصدير', 'description' => 'وصف حاجة سري', 'priority' => 'URGENT']);
        $this->need($h['family'], ['title' => 'حاجة مغلقة', 'status' => 'CLOSED']);
        \App\Models\FamilyNeed::where('title', 'حاجة مغلقة')->update(['closure_reason' => 'سبب إغلاق سري']);
        $this->assessment($h['family'], 'SHELTER', 'HIGH', '2026-09-01', notes: 'ملاحظة تقييم سرية');
        $this->family(2); // unassigned family

        $this->external = $this->openAssistanceOf('EXTERNAL', ['title' => 'كشف تصدير تجريبي']);
        $this->actingAs($this->user)->putJson("/api/v1/assistances/{$this->external->uuid}/export-configuration", ['fields' => [
            ['field_key' => 'national_id', 'column_label' => 'رقم الهوية'],
            ['field_key' => 'primary_mobile', 'column_label' => 'الجوال'],
        ]])->assertOk();
        $b = $this->approvedBeneficiary($this->external, $h['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->external->uuid}/beneficiary-lists", ['beneficiary_ids' => [$b->uuid]])->assertCreated();
        $this->reject($this->external, $this->nominate($this->external, $this->household(['national_id' => '870000002'])['family']), 'سبب رفض سري')->assertOk();
    }

    private function assertPrivate($response): void
    {
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $raw = $this->rawXlsx($response->getContent());
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
    }

    public function test_every_export_is_authenticated_permissioned_private_and_rtl(): void
    {
        // setUp() acted as a user: forget it for the unauthenticated check.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/population/export?clan=AL_BREEM')->assertUnauthorized();
        // Report access without export.basic.
        $this->actingAs($this->user('DATA_ENTRY'))->getJson('/api/v1/reports/needs/export?clan=AL_BREEM')->assertForbidden();
        // export.basic without the domain permission.
        $this->actingAs($this->user('REPORTS_VIEWER'))->getJson('/api/v1/reports/health/export?clan=AL_BREEM')->assertForbidden();
        $this->actingAs($this->user('FAMILY_USER'))->getJson('/api/v1/reports/population/export?clan=AL_BREEM')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/v1/reports/unknown/export?clan=AL_BREEM')->assertNotFound();

        foreach (['population', 'health', 'needs', 'assessments', 'assistance', 'data-quality'] as $report) {
            [$response, $sheets] = $this->exportOf($report);
            $this->assertPrivate($response);
            $response->assertHeader('Content-Disposition', "attachment; filename=\"{$report}-report-2026-09-24.xlsx\"");
            $info = $sheets['معلومات التقرير'];
            $this->assertSame(['البند', 'القيمة'], $info[0]);
            $this->assertSame(['العشيرة / العائلة', 'عائلة البريم'], $info[2]);
        }
    }

    public function test_population_and_health_workbooks_have_exact_aggregate_sheets(): void
    {
        [, $population] = $this->exportOf('population');
        $this->assertSame(['معلومات التقرير', 'الملخص', 'الفئات العمرية', 'التوزيع التنظيمي', 'أماكن النزوح'], array_keys($population));
        $this->assertSame(['الأسر النشطة', '3'], $population['الملخص'][1]);
        $this->assertSame(['مجموعة الفروع', 'الفرع', 'عدد الأسر', 'عدد الأفراد'], $population['التوزيع التنظيمي'][0]);
        $this->assertContains(['غير محدد', 'غير محدد', '2', '3'], $population['التوزيع التنظيمي']);
        $this->assertSame(['أقل من سنتين', '2–5 سنوات', '6–17 سنة', '18–59 سنة', '60 سنة فأكثر', 'العمر غير معروف'], array_column(array_slice($population['الفئات العمرية'], 1), 0));

        // Branch scope: the workbook follows the scope and has no empty
        // organizational sheet.
        [, $branch] = $this->exportOf('population', ['branch' => 'ABU_TEIMA']);
        $this->assertSame(['معلومات التقرير', 'الملخص', 'الفئات العمرية', 'أماكن النزوح'], array_keys($branch));
        $this->assertSame(['الفرع', 'أبو تيمة'], $branch['معلومات التقرير'][4]);
        $this->assertSame(['الأسر النشطة', '1'], $branch['الملخص'][1]);

        [, $health] = $this->exportOf('health');
        $this->assertSame(['معلومات التقرير', 'المؤشرات الصحية', 'الإعاقة حسب النوع'], array_keys($health));
        $this->assertSame([['المؤشر', 'عدد الأفراد'], ['ذوو إعاقة', '0'], ['أمراض مزمنة', '1'], ['حمل نشط', '0'], ['رضاعة نشطة', '0']], $health['المؤشرات الصحية']);
    }

    public function test_needs_export_follows_filters_and_has_exact_columns(): void
    {
        [, $sheets] = $this->exportOf('needs', ['status' => 'OPEN']);
        $rows = $sheets['الاحتياجات'];
        $this->assertSame(['العنوان', 'الفئة', 'الأولوية', 'الحالة', 'نوع المستهدف', 'رمز المستهدف', 'اسم المستهدف', 'رقم الأسرة', 'تاريخ الإنشاء', 'تاريخ الإغلاق / التلبية'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame(['حاجة تصدير', 'الغذاء', 'عاجلة', 'مفتوح', 'الأسرة'], array_slice($rows[1], 0, 5));
        $this->assertContains(['الحالة', 'مفتوح'], $sheets['معلومات التقرير']);

        // Scope follows: another branch has no Needs.
        [, $other] = $this->exportOf('needs', ['branch' => 'BREEM_ABU_HANNUN']);
        $this->assertCount(1, $other['الاحتياجات']);
        $this->assertContains(['الفرع', 'البريم - أبو حنون'], $other['معلومات التقرير']);
    }

    public function test_assessment_assistance_and_data_quality_exports(): void
    {
        [, $assessments] = $this->exportOf('assessments', ['domain' => 'SHELTER', 'rating' => 'HIGH']);
        $this->assertSame(['المجال', 'لا يوجد احتياج', 'منخفض', 'متوسط', 'مرتفع', 'حرج', 'أسر مُقيَّمة', 'أسر غير مُقيَّمة'], $assessments['المجالات'][0]);
        $this->assertSame(['رقم الأسرة', 'رب الأسرة', 'الفرع', 'تاريخ التقييم', 'التصنيف'], $assessments['الأسر'][0]);
        $this->assertSame(['رب أسرة التصدير', 'أبو تيمة', '2026-09-01', 'مرتفع'], array_slice($assessments['الأسر'][1], 1));

        [, $assistance] = $this->exportOf('assistance');
        $header = $assistance['البرامج'][0];
        $this->assertCount(17, $header);
        $this->assertContains('تم إصدارهم في كشوف', $header);
        $row = array_combine($header, array_pad($assistance['البرامج'][1], 17, null));
        $this->assertSame(['كشف تصدير تجريبي', 'خارجي', '1', '1', null], [$row['المساعدة'], $row['نمط التنفيذ'], $row['تم إصدارهم في كشوف'], $row['عدد الكشوف الصادرة'], $row['تم التسليم']]);

        [, $quality] = $this->exportOf('data-quality', ['issue' => 'PERSON_MISSING_NATIONAL_ID']);
        $this->assertSame(['المشكلة', 'النوع', 'السجل', 'عدد السجلات'], $quality['المشكلات'][0]);
        $this->assertSame(['رقم الفرد', 'الاسم', 'رقم الأسرة', 'الفرع'], $quality['السجلات'][0]);
        // The household head has a National ID: only the two unassigned members.
        $this->assertCount(3, $quality['السجلات']);
    }
}
