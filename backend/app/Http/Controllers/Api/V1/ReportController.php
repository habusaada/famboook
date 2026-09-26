<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportRequest;
use App\Models\AssessmentDomain;
use App\Models\User;
use App\Support\Reporting\AssessmentReport;
use App\Support\Reporting\AssistanceReport;
use App\Support\Reporting\DataQualityReport;
use App\Support\Reporting\NeedsReport;
use App\Support\Reporting\PopulationAggregates;
use App\Support\Reporting\PopulationReport;
use App\Support\Reporting\ReportExport as X;
use App\Support\Reporting\ReportPage;
use App\Support\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports V1 (docs/03 §55b, docs/06 §59b, permission report.view). Six
 * fixed reports over the shared organizational scope, derived on request.
 * Each report also requires its domain view permissions (REPORT_PERMISSIONS);
 * XLSX export additionally requires export.basic.
 */
class ReportController extends Controller
{
    /** Domain permissions each report requires (all of them). */
    public const REPORT_PERMISSIONS = [
        'population' => ['family.view', 'person.view'],
        'health' => ['person.view', 'health-record.view'],
        'needs' => ['family.view', 'need.view'],
        'assessments' => ['family.view', 'assessment.view'],
        'assistance' => ['assistance.view'],
        'data-quality' => ['family.view', 'person.view'],
    ];

    public static function canView(User $user, string $report): bool
    {
        foreach (self::REPORT_PERMISSIONS[$report] as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    private function authorizeReport(Request $request, string $report, bool $export = false): void
    {
        abort_unless(self::canView($request->user(), $report), 403, 'لا تملك صلاحية عرض هذا التقرير.');
        abort_if($export && ! $request->user()->can('export.basic'), 403, 'لا تملك صلاحية تصدير التقارير.');
    }

    /** Which reports this user may open and whether they may export. */
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'reports' => collect(self::REPORT_PERMISSIONS)->map(fn ($p, $report) => self::canView($user, $report)),
            'can_export' => $user->can('export.basic'),
        ]]);
    }

    private function envelope(ReportRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => [
            'scope' => $request->scope()->toArray(),
            'generated_at' => now()->toIso8601String(),
            ...$data,
        ]]);
    }

    // --------------------------------------------------------------- reports

    public function population(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'population');

        return $this->envelope($request, (new PopulationReport($request->scope(), today()))->toArray());
    }

    public function health(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'health');

        // Aggregate only: there is deliberately no person-level drill-down.
        return $this->envelope($request, ['health' => (new PopulationAggregates($request->scope(), today()))->health()]);
    }

    private function needsReport(ReportRequest $request): NeedsReport
    {
        return new NeedsReport($request->scope(), [
            'status' => $request->needStatus(),
            'priority' => $request->input('priority'),
            'category' => $request->input('category'),
            'target' => $request->input('target'),
        ]);
    }

    public function needs(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'needs');
        $report = $this->needsReport($request);

        return $this->envelope($request, [
            'summary' => $report->summary(),
            'rows' => ReportPage::of($report->rowsQuery(), $request->perPage(), NeedsReport::row(...)),
        ]);
    }

    public function assessments(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'assessments');

        return $this->envelope($request, (new AssessmentReport($request->scope(), today()))->toArray());
    }

    private function domainId(ReportRequest $request): int
    {
        abort_unless($request->filled('domain') && $request->filled('rating'), 422, 'المجال والتصنيف مطلوبان.');
        $id = AssessmentDomain::where('code', $request->input('domain'))->value('id');
        abort_if($id === null, 422, 'مجال التقييم غير موجود.');

        return $id;
    }

    public function assessmentFamilies(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'assessments');
        $query = (new AssessmentReport($request->scope(), today()))->familiesQuery($this->domainId($request), $request->input('rating'));

        return $this->envelope($request, [
            'domain' => $request->input('domain'),
            'rating' => $request->input('rating'),
            'rows' => ReportPage::of($query, $request->perPage(), AssessmentReport::row(...)),
        ]);
    }

    public function assistance(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'assistance');
        $programs = (new AssistanceReport($request->scope(), $request->assistanceStatuses()))->programs();
        $perPage = $request->perPage();
        $page = max($request->integer('page', 1), 1);

        return $this->envelope($request, [
            'statuses' => $request->assistanceStatuses(),
            'totals' => AssistanceReport::totals($programs),
            'rows' => [
                'data' => $programs->forPage($page, $perPage)->values()->all(),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max((int) ceil($programs->count() / $perPage), 1),
                    'per_page' => $perPage,
                    'total' => $programs->count(),
                ],
            ],
        ]);
    }

    public function dataQuality(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'data-quality');

        return $this->envelope($request, [
            'issues' => (new DataQualityReport($request->scope()))->overview(),
            // Protected by the composite foreign key; never a report metric.
            'integrity_guaranteed' => ['FAMILY_BRANCH_CLAN_MATCH'],
        ]);
    }

    public function dataQualityRecords(ReportRequest $request): JsonResponse
    {
        $this->authorizeReport($request, 'data-quality');
        abort_unless($request->filled('issue'), 422, 'نوع المشكلة مطلوب.');
        $issue = $request->input('issue');
        $entity = DataQualityReport::entityOf($issue);

        return $this->envelope($request, [
            'issue' => $issue,
            'entity' => $entity,
            'rows' => ReportPage::of(
                (new DataQualityReport($request->scope()))->recordsQuery($issue),
                $request->perPage(),
                fn ($r) => DataQualityReport::row($entity, $r),
            ),
        ]);
    }

    // ---------------------------------------------------------------- export

    public function export(ReportRequest $request, string $report): Response
    {
        abort_unless(isset(self::REPORT_PERMISSIONS[$report]), 404);
        $this->authorizeReport($request, $report, export: true);
        $scope = $request->scope();
        $at = now();

        [$filters, $sheets] = match ($report) {
            'population' => $this->populationSheets($request),
            'health' => $this->healthSheets($request),
            'needs' => $this->needsSheets($request),
            'assessments' => $this->assessmentSheets($request),
            'assistance' => $this->assistanceSheets($request),
            'data-quality' => $this->dataQualitySheets($request),
        };

        $bytes = XlsxWriter::workbook([X::infoSheet($report, $scope, $filters, $at), ...$sheets]);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.X::filename($report, $at).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function populationSheets(ReportRequest $request): array
    {
        $data = (new PopulationReport($request->scope(), today()))->toArray();
        $s = $data['summary'];
        $organization = [];
        foreach ($data['organization']['groups'] ?? [] as $group) {
            $label = $group['name'] ?? $group['display_name'] ?? $group['code'];
            $organization[] = [$label, 'إجمالي المجموعة', $group['families'], $group['people']];
            foreach ($group['branches'] as $branch) {
                $organization[] = [$label, $branch['name'], $branch['families'], $branch['people']];
            }
        }
        if ($data['organization']['unassigned'] ?? null) {
            $organization[] = ['غير محدد', 'غير محدد', $data['organization']['unassigned']['families'], $data['organization']['unassigned']['people']];
        }

        return [[], [
            ['name' => 'الملخص', 'headers' => ['المؤشر', 'العدد'], 'rows' => [
                ['الأسر النشطة', $s['active_families']],
                ['الأفراد الحاليون', $s['current_people']],
                ['ذكور', $s['male']],
                ['إناث', $s['female']],
                ['جنس غير محدد', $s['unknown_gender']],
                ['أسر نازحة', $s['displaced']],
                ['أسر غير نازحة', $s['not_displaced']],
                ['نزوح غير معروف / غير مسجّل', $s['unknown_displacement']],
            ]],
            ['name' => 'الفئات العمرية', 'headers' => ['الفئة العمرية', 'عدد الأفراد'],
                'rows' => array_map(fn ($b) => [X::AGE_BAND[$b['code']], $b['count']], $data['age_bands'])],
            // No organizational breakdown at Branch scope.
            ...($data['organization'] === null ? [] : [
                ['name' => 'التوزيع التنظيمي', 'headers' => ['مجموعة الفروع', 'الفرع', 'عدد الأسر', 'عدد الأفراد'], 'rows' => $organization],
            ]),
            ['name' => 'أماكن النزوح', 'headers' => ['مكان النزوح (كما هو مسجّل)', 'عدد الأسر'],
                'rows' => array_map(fn ($l) => [$l['location'], $l['families']], $data['top_locations'])],
        ]];
    }

    private function healthSheets(ReportRequest $request): array
    {
        $h = (new PopulationAggregates($request->scope(), today()))->health();

        // Aggregate tables only — never person-level health rows.
        return [[], [
            ['name' => 'المؤشرات الصحية', 'headers' => ['المؤشر', 'عدد الأفراد'], 'rows' => [
                ['ذوو إعاقة', $h['people_with_disability']],
                ['أمراض مزمنة', $h['people_with_chronic_disease']],
                ['حمل نشط', $h['active_pregnancy']],
                ['رضاعة نشطة', $h['active_breastfeeding']],
            ]],
            ['name' => 'الإعاقة حسب النوع', 'headers' => ['نوع الإعاقة', 'عدد الأفراد'],
                'rows' => array_map(fn ($t) => [$t['name'] ?? 'غير محدد', $t['people']], $h['disability_types'])],
        ]];
    }

    private function needsSheets(ReportRequest $request): array
    {
        $report = $this->needsReport($request);
        $s = $report->summary();
        $filters = array_filter([
            'الحالة' => X::NEED_STATUS[$request->needStatus() ?? ''] ?? null,
            'الأولوية' => X::PRIORITY[$request->input('priority') ?? ''] ?? null,
            'الفئة' => $request->input('category') ? (collect($s['by_category'])->firstWhere('code', $request->input('category'))['name'] ?? $request->input('category')) : null,
            'نوع المستهدف' => X::TARGET[$request->input('target') ?? ''] ?? null,
        ]);

        return [$filters, [
            ['name' => 'الملخص', 'headers' => ['البند', 'القيمة', 'العدد'], 'rows' => [
                ['الإجمالي', '', $s['total']],
                ['الحالة', X::NEED_STATUS['OPEN'], $s['open']],
                ['الحالة', X::NEED_STATUS['FULFILLED'], $s['fulfilled']],
                ['الحالة', X::NEED_STATUS['CLOSED'], $s['closed']],
                ...array_map(fn ($p) => ['الأولوية', X::PRIORITY[$p['priority']], $p['count']], $s['by_priority']),
                ['نوع المستهدف', X::TARGET['FAMILY'], $s['by_target']['family']],
                ['نوع المستهدف', X::TARGET['PERSON'], $s['by_target']['person']],
                ...array_map(fn ($c) => ['الفئة', $c['name'], $c['count']], $s['by_category']),
            ]],
            ['name' => 'الاحتياجات', 'headers' => ['العنوان', 'الفئة', 'الأولوية', 'الحالة', 'نوع المستهدف', 'رمز المستهدف', 'اسم المستهدف', 'رقم الأسرة', 'تاريخ الإنشاء', 'تاريخ الإغلاق / التلبية'],
                'rows' => X::stream($report->rowsQuery(), function ($n) {
                    $r = NeedsReport::row($n);

                    return [$r['title'], $r['category']['name'], X::PRIORITY[$r['priority']], X::NEED_STATUS[$r['status']], X::TARGET[$r['target_type']],
                        $r['target']['code'], $r['target']['name'], $r['family_code'], $r['created_date'], $r['resolved_date']];
                })],
        ]];
    }

    private function assessmentSheets(ReportRequest $request): array
    {
        $report = new AssessmentReport($request->scope(), today());
        $data = $report->toArray();
        $ratings = ['NONE', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
        $sheets = [
            ['name' => 'المجالات', 'headers' => ['المجال', ...array_map(fn ($r) => X::RATING[$r], $ratings), 'أسر مُقيَّمة', 'أسر غير مُقيَّمة'],
                'rows' => array_map(fn ($d) => [$d['name'], ...array_map(fn ($r) => $d['ratings'][$r], $ratings), $d['assessed_families'], $d['not_assessed_families']], $data['domains'])],
            ['name' => 'سجلات التقييم', 'headers' => ['حالة التقييم', 'عدد التقييمات'], 'rows' => [
                ['مسودة', $data['lifecycle']['draft']],
                ['مكتمل', $data['lifecycle']['completed']],
            ]],
        ];
        $filters = [];
        if ($request->filled('domain') || $request->filled('rating')) {
            $domainId = $this->domainId($request);
            $filters = [
                'المجال' => AssessmentDomain::whereKey($domainId)->value('name'),
                'التصنيف' => X::RATING[$request->input('rating')],
            ];
            $sheets[] = ['name' => 'الأسر', 'headers' => ['رقم الأسرة', 'رب الأسرة', 'الفرع', 'تاريخ التقييم', 'التصنيف'],
                'rows' => X::stream($report->familiesQuery($domainId, $request->input('rating')), function ($r) {
                    $row = AssessmentReport::row($r);

                    return [$row['family_code'], $row['household_head'], $row['branch'], $row['assessment_date'], X::RATING[$row['rating']]];
                })];
        }

        return [$filters, $sheets];
    }

    private function assistanceSheets(ReportRequest $request): array
    {
        $programs = (new AssistanceReport($request->scope(), $request->assistanceStatuses()))->programs();
        $filters = ['حالة المساعدة' => implode('، ', array_map(fn ($s) => X::ASSISTANCE_STATUS[$s], $request->assistanceStatuses()))];

        return [$filters, [
            ['name' => 'البرامج', 'headers' => [
                'المساعدة', 'الفئة', 'الجهة المنفذة', 'نمط التنفيذ', 'الحالة', 'العدد المستهدف للبرنامج',
                'مرشحون', 'معتمدون', 'مرفوضون',
                'بانتظار التسليم', 'تم التسليم', 'لم يتم التسليم', 'تسليمات معكوسة', 'نسبة التسليم من المعتمدين ٪',
                'معتمدون لم يُصدروا في كشف', 'تم إصدارهم في كشوف', 'عدد الكشوف الصادرة',
            ], 'rows' => $programs->map(fn ($p) => [
                $p['title'], $p['category']['name'], $p['provider'], X::MODE[$p['execution_mode']], X::ASSISTANCE_STATUS[$p['status']], $p['target'],
                $p['nominated'], $p['approved'], $p['rejected'],
                $p['internal']['awaiting_delivery'] ?? null, $p['internal']['delivered'] ?? null, $p['internal']['not_delivered'] ?? null,
                $p['internal']['reversed_deliveries'] ?? null, $p['internal']['delivery_percentage'] ?? null,
                $p['external']['approved_not_listed'] ?? null, $p['external']['listed_unique'] ?? null, $p['external']['issued_lists'] ?? null,
            ])->all()],
        ]];
    }

    private function dataQualitySheets(ReportRequest $request): array
    {
        $report = new DataQualityReport($request->scope());
        $sheets = [
            ['name' => 'المشكلات', 'headers' => ['المشكلة', 'النوع', 'السجل', 'عدد السجلات'],
                'rows' => array_map(fn ($i) => [
                    X::ISSUE[$i['code']],
                    $i['group'] === DataQualityReport::COMPLETENESS ? 'اكتمال' : 'اتساق',
                    $i['entity'] === 'FAMILY' ? 'أسرة' : 'فرد',
                    $i['count'],
                ], $report->overview())],
        ];
        $filters = [];
        if ($request->filled('issue')) {
            $issue = $request->input('issue');
            $entity = DataQualityReport::entityOf($issue);
            $filters = ['المشكلة' => X::ISSUE[$issue]];
            // Identifying and navigation fields only — never the value in question.
            $sheets[] = $entity === 'FAMILY'
                ? ['name' => 'السجلات', 'headers' => ['رقم الأسرة', 'رب الأسرة', 'العشيرة / العائلة', 'الفرع'],
                    'rows' => X::stream($report->recordsQuery($issue), fn ($r) => array_values(DataQualityReport::row('FAMILY', $r)))]
                : ['name' => 'السجلات', 'headers' => ['رقم الفرد', 'الاسم', 'رقم الأسرة', 'الفرع'],
                    'rows' => X::stream($report->recordsQuery($issue), fn ($r) => array_values(DataQualityReport::row('PERSON', $r)))];
        }

        return [$filters, $sheets];
    }
}
