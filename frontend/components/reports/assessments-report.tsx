"use client";

import Link from "next/link";
import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Code } from "@/components/shared/page-layout";
import { fmt } from "@/components/dashboard/dashboard-sections";
import { AssessmentRatingTag } from "@/components/assessments/assessment-case";
import {
  EmptyNote,
  ExportButton,
  ReportPagination,
  ReportSection,
  ReportState,
  ReportToolbar,
  StatStrip,
} from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { AssessmentBucket, AssessmentFamiliesReport, AssessmentsReport as AssessmentsData } from "@/lib/types/api/reports";
import { ASSESSMENT_RATINGS, NOT_ASSESSED_LABEL, assessmentRatingLabels } from "@/lib/utils/assessment";
import { cn } from "@/lib/utils";

const bucketLabel = (b: AssessmentBucket) => (b === "NOT_ASSESSED" ? NOT_ASSESSED_LABEL : assessmentRatingLabels[b]);
const bucketTag = (b: AssessmentBucket) => <AssessmentRatingTag rating={b === "NOT_ASSESSED" ? null : b} />;
const head = "h-10 text-xs font-medium text-muted-foreground";

function Drill({
  scope,
  domain,
  domainName,
  rating,
  page,
  onPage,
  onClose,
  canExport,
}: {
  scope: ReportParams;
  domain: string;
  domainName: string;
  rating: AssessmentBucket;
  page: string | undefined;
  onPage: (page: number) => void;
  onClose: () => void;
  canExport: boolean;
}) {
  const report = useReport<AssessmentFamiliesReport>("assessments/families", { ...scope, domain, rating, page });
  // Previous data is kept only while paging the same bucket, never shown
  // under another domain/rating.
  const current = report.data?.data;
  const data = current?.domain === domain && current.rating === rating ? current : undefined;
  return (
    <ReportSection
      title={
        <span className="flex flex-wrap items-center gap-2">
          {domainName} {bucketTag(rating)}
        </span>
      }
      description="الأسر الواقعة حاليًا في هذه الخانة (حسب آخر تقييم مكتمل للمجال)."
      action={
        <div className="flex items-center gap-1">
          <ExportButton report="assessments" params={{ ...scope, domain, rating }} canExport={canExport} />
          <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="إغلاق قائمة الأسر">
            <X className="size-4" />
          </Button>
        </div>
      }
      flush
      data-drill={`${domain}:${rating}`}
    >
      <ReportState isLoading={!data} error={report.error}>
        {data &&
          (data.rows.data.length === 0 ? (
            <div className="px-4 py-6 text-center sm:px-5">
              <EmptyNote>لا توجد أسر في هذه الخانة.</EmptyNote>
            </div>
          ) : (
            <>
              <Table className="hidden md:table">
                <TableHeader className="bg-surface-1">
                  <TableRow className="border-stroke-subtle hover:bg-transparent">
                    <TableHead className={`${head} ps-5`}>رقم الأسرة</TableHead>
                    <TableHead className={head}>رب الأسرة</TableHead>
                    <TableHead className={head}>الفرع</TableHead>
                    <TableHead className={head}>تاريخ التقييم</TableHead>
                    <TableHead className={`${head} pe-5`}>التصنيف</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.rows.data.map((r) => (
                    <TableRow key={r.family_code} data-family={r.family_code} className="border-stroke-subtle">
                      <TableCell className="ps-5">
                        <Link href={`/families/${r.family_code}`} className="rounded-sm font-semibold text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                          <Code>{r.family_code}</Code>
                        </Link>
                      </TableCell>
                      <TableCell>{r.household_head ?? "—"}</TableCell>
                      <TableCell className={r.branch ? undefined : "text-muted-foreground"}>{r.branch ?? "غير محدد"}</TableCell>
                      <TableCell>
                        {r.assessment_id ? (
                          <Link href={`/families/${r.family_code}/assessments/${r.assessment_id}`} className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            <bdi dir="ltr" className="tabular-nums">{r.assessment_date}</bdi>
                          </Link>
                        ) : (
                          "—"
                        )}
                      </TableCell>
                      <TableCell className="pe-5">{bucketTag(r.rating)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <ul className="divide-y divide-stroke-subtle md:hidden" aria-label="الأسر في الخانة">
                {data.rows.data.map((r) => (
                  <li key={r.family_code} className="flex flex-col gap-1 px-4 py-3" data-family={r.family_code}>
                    <div className="flex items-center justify-between gap-2">
                      <Link href={`/families/${r.family_code}`} className="rounded-sm font-semibold text-brand-800 hover:underline">
                        <Code>{r.family_code}</Code>
                      </Link>
                      {bucketTag(r.rating)}
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {r.household_head ?? "—"} · {r.branch ?? "غير محدد"}
                      {r.assessment_date && (
                        <>
                          {" "}· تقييم <bdi dir="ltr" className="tabular-nums">{r.assessment_date}</bdi>
                        </>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          ))}
        {data && <ReportPagination meta={data.rows.meta} onPage={onPage} label="أسرة" />}
      </ReportState>
    </ReportSection>
  );
}

export function AssessmentsReport({
  scope,
  filters,
  setFilters,
  canExport,
}: {
  scope: ReportParams;
  filters: Record<string, string>;
  setFilters: (patch: Record<string, string>, resetPage?: boolean) => void;
  canExport: boolean;
}) {
  const report = useReport<AssessmentsData>("assessments", scope);
  const data = report.data?.data;
  const drillDomain = data?.domains.find((d) => d.code === filters.domain);
  const rating = filters.rating as AssessmentBucket | undefined;

  const cell = (domain: string, name: string, bucket: AssessmentBucket, count: number) => (
    <button
      type="button"
      data-cell={`${domain}:${bucket}`}
      disabled={count === 0}
      aria-label={`${name} — ${bucketLabel(bucket)}: ${count} أسرة${count > 0 ? "، عرض الأسر" : ""}`}
      onClick={() => setFilters({ domain, rating: bucket }, true)}
      className={cn(
        "min-w-10 rounded-control px-2 py-1 tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-ring",
        count === 0 ? "text-muted-foreground" : "font-semibold text-brand-800 underline-offset-2 hover:bg-surface-hover hover:underline",
        filters.domain === domain && filters.rating === bucket && "bg-surface-selected ring-1 ring-brand-700/40"
      )}
    >
      {fmt(count)}
    </button>
  );

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        context="لا توجد مرشحات إضافية؛ انقر رقمًا في الجدول لعرض الأسر في الخانة."
        exportAction={<ExportButton report="assessments" params={scope} canExport={canExport} />}
      />
      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <StatStrip
              label="ملخص التقييمات"
              items={[
                { label: "الأسر النشطة ضمن النطاق", value: data.total_families },
                { label: "تقييمات مكتملة", value: data.lifecycle.completed, hint: "عدد سجلات التقييم" },
                { label: "مسودات تقييم", value: data.lifecycle.draft, hint: "لا تدخل في الجدول التحليلي" },
              ]}
            />

            <ReportSection
              title="الحالة الحالية لكل مجال"
              description={
                <>
                  لكل أسرة ومجال: آخر تقييم مكتمل قيّم المجال. «{NOT_ASSESSED_LABEL}» يعني عدم وجود أي تقييم مكتمل للمجال — وليس «لا يوجد احتياج». كل
                  مجال مستقل؛ لا يُحتسب مجموع أو درجة كلية.
                </>
              }
              flush
              data-section="matrix"
            >
              {/* A genuine matrix: horizontal scroll stays inside this container. */}
              <div className="overflow-x-auto">
                <Table className="min-w-[760px]">
                  <TableHeader className="bg-surface-1">
                    <TableRow className="border-stroke-subtle hover:bg-transparent">
                      <TableHead className={`${head} ps-5`}>المجال</TableHead>
                      {ASSESSMENT_RATINGS.map((r) => (
                        <TableHead key={r} className={`${head} text-center`}>
                          {assessmentRatingLabels[r]}
                        </TableHead>
                      ))}
                      <TableHead className={`${head} text-center`}>مُقيَّمة</TableHead>
                      <TableHead className={`${head} pe-5 text-center`}>{NOT_ASSESSED_LABEL}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.domains.map((d) => (
                      <TableRow key={d.code} data-domain={d.code} className="border-stroke-subtle">
                        <TableCell className="ps-5 font-semibold">{d.name}</TableCell>
                        {ASSESSMENT_RATINGS.map((r) => (
                          <TableCell key={r} className="text-center">
                            {cell(d.code, d.name, r, d.ratings[r])}
                          </TableCell>
                        ))}
                        <TableCell className="text-center tabular-nums text-muted-foreground">{fmt(d.assessed_families)}</TableCell>
                        <TableCell className="pe-5 text-center">{cell(d.code, d.name, "NOT_ASSESSED", d.not_assessed_families)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </ReportSection>

            {drillDomain && rating && (
              <Drill
                scope={scope}
                domain={drillDomain.code}
                domainName={drillDomain.name}
                rating={rating}
                page={filters.page}
                onPage={(page) => setFilters({ page: String(page) })}
                onClose={() => setFilters({ domain: "", rating: "" }, true)}
                canExport={canExport}
              />
            )}
          </>
        )}
      </ReportState>
    </div>
  );
}
