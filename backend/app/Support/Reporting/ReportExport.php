<?php

namespace App\Support\Reporting;

use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Query\Builder;

/**
 * XLSX workbooks for Reports V1 (docs/03 §55b): fixed Arabic columns per
 * report — no arbitrary columns — generated on request, never stored.
 * The first sheet records the exact scope and filters. Only the fields
 * the report already shows: no National IDs, phone numbers, health
 * details, notes, descriptions, reasons, snapshot values or internal ids.
 */
final class ReportExport
{
    public const PRIORITY = ['URGENT' => 'عاجلة', 'HIGH' => 'مرتفعة', 'MEDIUM' => 'متوسطة', 'LOW' => 'منخفضة'];

    public const NEED_STATUS = ['OPEN' => 'مفتوح', 'FULFILLED' => 'تمت تلبيته', 'CLOSED' => 'مغلق'];

    public const TARGET = ['FAMILY' => 'الأسرة', 'PERSON' => 'فرد'];

    public const RATING = [
        'NONE' => 'لا يوجد احتياج', 'LOW' => 'منخفض', 'MEDIUM' => 'متوسط', 'HIGH' => 'مرتفع', 'CRITICAL' => 'حرج',
        'NOT_ASSESSED' => 'لم يتم تقييمه',
    ];

    public const ASSISTANCE_STATUS = ['DRAFT' => 'مسودة', 'OPEN' => 'مفتوحة', 'COMPLETED' => 'مكتملة', 'CANCELLED' => 'ملغاة'];

    public const MODE = ['INTERNAL' => 'داخلي', 'EXTERNAL' => 'خارجي'];

    public const AGE_BAND = [
        'UNDER_2' => 'أقل من سنتين', 'AGE_2_5' => '2–5 سنوات', 'AGE_6_17' => '6–17 سنة',
        'AGE_18_59' => '18–59 سنة', 'AGE_60_PLUS' => '60 سنة فأكثر', 'UNKNOWN' => 'العمر غير معروف',
    ];

    public const ISSUE = [
        'FAMILY_WITHOUT_BRANCH' => 'أسر بلا فرع محدد',
        'FAMILY_WITHOUT_CURRENT_RESIDENCE' => 'أسر بلا سكن حالي مسجّل',
        'DISPLACED_WITHOUT_LOCATION' => 'أسر نازحة بلا مكان نزوح مسجّل',
        'PERSON_MISSING_NATIONAL_ID' => 'أفراد بلا رقم هوية',
        'PERSON_MISSING_BIRTH_DATE' => 'أفراد بلا تاريخ ميلاد',
        'PERSON_MISSING_MOBILE' => 'أفراد بلا رقم جوال أساسي',
        'PERSON_UNKNOWN_GENDER' => 'أفراد بجنس غير محدد',
        'PERSON_MARITAL_STATUS_UNKNOWN' => 'أفراد بحالة اجتماعية غير معروفة',
        'ACTIVE_FAMILY_WITHOUT_HEAD' => 'أسر نشطة بلا رب أسرة حالي',
        'HOUSEHOLD_HEAD_DECEASED' => 'أسر رب أسرتها الحالي متوفى (تحتاج مراجعة)',
    ];

    public const REPORT_NAME = [
        'population' => 'السكان والأسر',
        'health' => 'الصحة',
        'needs' => 'الاحتياجات',
        'assessments' => 'التقييمات',
        'assistance' => 'المساعدات',
        'data-quality' => 'جودة البيانات',
    ];

    /**
     * The "report information" sheet: report, exact scope, filters, time.
     *
     * @param  array<string, string>  $filters  Arabic label => Arabic value
     * @return array<string, mixed>
     */
    public static function infoSheet(string $report, OrganizationalScope $scope, array $filters, CarbonInterface $at): array
    {
        $group = $scope->group ? ($scope->group->name ?? $scope->group->loadMissing('branches')->displayName() ?? $scope->group->code) : 'جميع المجموعات';
        $rows = [
            ['التقرير', self::REPORT_NAME[$report]],
            ['العشيرة / العائلة', $scope->clan->name],
            ['مجموعة الفروع', $group],
            ['الفرع', $scope->branch?->name ?? 'جميع الفروع'],
        ];
        foreach ($filters as $label => $value) {
            $rows[] = [$label, $value];
        }
        $rows[] = ['تاريخ الإنشاء', $at->format('Y-m-d H:i')];
        $rows[] = ['ملاحظة', 'أرقام مشتقة من السجل الحالي وقت إنشاء الملف.'];

        return ['name' => 'معلومات التقرير', 'headers' => ['البند', 'القيمة'], 'rows' => $rows];
    }

    /**
     * Streams query rows in id-free chunks (keyset on the given unique
     * sort column is not required; lazy() pages by the query's order).
     *
     * @param  callable(object): list<string|int|float|null>  $map
     */
    public static function stream(Builder $query, callable $map): Generator
    {
        foreach ($query->lazy(1000) as $row) {
            yield $map($row);
        }
    }

    public static function filename(string $report, CarbonInterface $at): string
    {
        return "{$report}-report-{$at->format('Y-m-d')}.xlsx";
    }
}
