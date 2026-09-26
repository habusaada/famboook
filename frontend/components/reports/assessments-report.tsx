"use client";

import Link from "next/link";
import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyNote, StatTile, fmt } from "@/components/dashboard/dashboard-sections";
import { ExportButton, ReportPagination, ReportState } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { AssessmentBucket, AssessmentFamiliesReport, AssessmentsReport as AssessmentsData } from "@/lib/types/api/reports";
import { ASSESSMENT_RATINGS, NOT_ASSESSED_LABEL, assessmentRatingLabels } from "@/lib/utils/assessment";
import { cn } from "cn";

const bucketLabel = (b: AssessmentBucket) => (b === "NOT_ASSESSED" ? NOT_ASSESSED_LABEL : assessmentRatingLabels[b]);

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
    <Card size="sm" data-drill={`${domain}:${rating}`}>
      <CardHeader>
        <CardTitle>
          {domainName} — {bucketLabel(rating)}
        </CardTitle>
        <CardDescription>الأسر الواقعة حاليًا في هذه الخانة (حسب آخر تقييم مكتمل للمجال)</CardDescription>
        <div className="col-start-2 row-span-2 row-start-1 flex items-center gap-1 self-start justify-self-end">
          <ExportButton report="assessments" params={{ ...scope, domain, rating }} canExport={canExport} label="تصدير" />
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="إغلاق">
            <X className="size-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <ReportState isLoading={!data} error={report.error}>
          {data && (data.rows.data.length === 0 ? <EmptyNote>لا توجد أسر في هذه الخانة.</EmptyNote> : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>رقم الأسرة</TableHead>
                    <TableHead>رب الأسرة</TableHead>
                    <TableHead>الفرع</TableHead>
                    <TableHead>تاريخ التقييم</TableHead>
                    <TableHead>التصنيف</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.rows.data.map((r) => (
                    <TableRow key={r.family_code} data-family={r.family_code}>
                      <TableCell><Link href={`/families/${r.family_code}`} className="font-medium hover:underline" dir="ltr">{r.family_code}</Link></TableCell>
                      <TableCell>{r.household_head ?? "—"}</TableCell>
                      <TableCell>{r.branch ?? "غير محدد"}</TableCell>
                      <TableCell dir="ltr" className="tabular-nums">
                        {r.assessment_id ? (
                          <Link href={`/families/${r.family_code}/assessments/${r.assessment_id}`} className="hover:underline">{r.assessment_date}</Link>
                        ) : "—"}
                      </TableCell>
                      <TableCell>{bucketLabel(r.rating)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          ))}
          {data && <ReportPagination meta={data.rows.meta} onPage={onPage} label="أسرة" />}
        </ReportState>
      </CardContent>
    </Card>
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

  const cell = (domain: string, bucket: AssessmentBucket, count: number) => (
    <button
      type="button"
      data-cell={`${domain}:${bucket}`}
      disabled={count === 0}
      onClick={() => setFilters({ domain, rating: bucket }, true)}
      className={cn(
        "min-w-10 rounded px-2 py-1 tabular-nums",
        count === 0 ? "text-muted-foreground/60" : "font-medium text-primary underline-offset-2 hover:bg-muted hover:underline",
        filters.domain === domain && filters.rating === bucket && "bg-muted ring-1 ring-primary/40",
      )}
    >
      {fmt(count)}
    </button>
  );

  return (
    <ReportState isLoading={!data} error={report.error}>
      {data && (
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <StatTile label="الأسر النشطة ضمن النطاق" value={data.total_families} />
              <StatTile label="تقييمات مكتملة" value={data.lifecycle.completed} hint="عدد سجلات التقييم" />
              <StatTile label="مسودات تقييم" value={data.lifecycle.draft} hint="لا تدخل في الجدول التحليلي" />
            </div>
            <ExportButton report="assessments" params={scope} canExport={canExport} />
          </div>

          <Card size="sm">
            <CardHeader>
              <CardTitle>الحالة الحالية لكل مجال</CardTitle>
              <CardDescription>
                لكل أسرة ومجال: آخر تقييم مكتمل قيّم المجال. «لم يتم تقييمه» يعني عدم وجود أي تقييم مكتمل للمجال — وليس «لا يوجد احتياج». انقر رقمًا لعرض الأسر.
              </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>المجال</TableHead>
                    {ASSESSMENT_RATINGS.map((r) => <TableHead key={r}>{assessmentRatingLabels[r]}</TableHead>)}
                    <TableHead>مُقيَّمة</TableHead>
                    <TableHead>{NOT_ASSESSED_LABEL}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.domains.map((d) => (
                    <TableRow key={d.code} data-domain={d.code}>
                      <TableCell className="font-medium">{d.name}</TableCell>
                      {ASSESSMENT_RATINGS.map((r) => <TableCell key={r}>{cell(d.code, r, d.ratings[r])}</TableCell>)}
                      <TableCell className="tabular-nums">{fmt(d.assessed_families)}</TableCell>
                      <TableCell>{cell(d.code, "NOT_ASSESSED", d.not_assessed_families)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

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
        </div>
      )}
    </ReportState>
  );
}
