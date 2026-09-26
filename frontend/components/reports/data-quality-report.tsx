"use client";

import Link from "next/link";
import { ChevronLeft, ShieldCheck, X } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyNote, fmt } from "@/components/dashboard/dashboard-sections";
import { ExportButton, ReportPagination, ReportState } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { DataQualityIssueCode, DataQualityRecordsReport, DataQualityReport as QualityData } from "@/lib/types/api/reports";
import { cn } from "cn";

/** Issue title and the actionable sentence ("N records need …"). */
export const issueLabels: Record<DataQualityIssueCode, { title: string; action: (n: string) => string }> = {
  FAMILY_WITHOUT_BRANCH: { title: "أسر بلا فرع محدد", action: (n) => `${n} أسرة تحتاج إلى تحديد الفرع` },
  FAMILY_WITHOUT_CURRENT_RESIDENCE: { title: "أسر بلا سكن حالي مسجّل", action: (n) => `${n} أسرة تحتاج إلى تسجيل السكن الحالي` },
  DISPLACED_WITHOUT_LOCATION: { title: "أسر نازحة بلا مكان نزوح", action: (n) => `${n} أسرة نازحة تحتاج إلى استكمال مكان النزوح` },
  PERSON_MISSING_NATIONAL_ID: { title: "أفراد بلا رقم هوية", action: (n) => `${n} سجلًا يحتاج إلى استكمال رقم الهوية` },
  PERSON_MISSING_BIRTH_DATE: { title: "أفراد بلا تاريخ ميلاد", action: (n) => `${n} سجلًا يحتاج إلى استكمال تاريخ الميلاد` },
  PERSON_MISSING_MOBILE: { title: "أفراد بلا رقم جوال أساسي", action: (n) => `${n} سجلًا بلا رقم جوال أساسي` },
  PERSON_UNKNOWN_GENDER: { title: "أفراد بجنس غير محدد", action: (n) => `${n} سجلًا يحتاج إلى تحديد الجنس` },
  PERSON_MARITAL_STATUS_UNKNOWN: { title: "أفراد بحالة اجتماعية غير معروفة", action: (n) => `${n} سجلًا بحالة اجتماعية غير معروفة` },
  ACTIVE_FAMILY_WITHOUT_HEAD: { title: "أسر نشطة بلا رب أسرة حالي", action: (n) => `${n} أسرة تحتاج إلى تحديد رب الأسرة` },
  HOUSEHOLD_HEAD_DECEASED: { title: "رب الأسرة الحالي متوفى", action: (n) => `${n} أسرة تحتاج إلى مراجعة رب الأسرة` },
};

function Records({
  scope,
  issue,
  page,
  onPage,
  onClose,
  canExport,
}: {
  scope: ReportParams;
  issue: DataQualityIssueCode;
  page: string | undefined;
  onPage: (page: number) => void;
  onClose: () => void;
  canExport: boolean;
}) {
  const report = useReport<DataQualityRecordsReport>("data-quality/records", { ...scope, issue, page });
  // Previous data is kept only while paging the same issue, never shown
  // under another issue.
  const current = report.data?.data;
  const data = current?.issue === issue ? current : undefined;

  return (
    <Card size="sm" data-records={issue}>
      <CardHeader>
        <CardTitle>{issueLabels[issue].title}</CardTitle>
        <CardDescription>السجلات المتأثرة — التصحيح يتم من صفحة الأسرة أو الفرد</CardDescription>
        <div className="col-start-2 row-span-2 row-start-1 flex items-center gap-1 self-start justify-self-end">
          <ExportButton report="data-quality" params={{ ...scope, issue }} canExport={canExport} label="تصدير" />
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="إغلاق">
            <X className="size-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <ReportState isLoading={!data} error={report.error}>
          {data && (data.rows.data.length === 0 ? <EmptyNote>لا توجد سجلات متأثرة ضمن النطاق.</EmptyNote> : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  {data.entity === "FAMILY" ? (
                    <TableRow>
                      <TableHead>رقم الأسرة</TableHead>
                      <TableHead>رب الأسرة</TableHead>
                      <TableHead>العشيرة / العائلة</TableHead>
                      <TableHead>الفرع</TableHead>
                    </TableRow>
                  ) : (
                    <TableRow>
                      <TableHead>رقم الفرد</TableHead>
                      <TableHead>الاسم</TableHead>
                      <TableHead>رقم الأسرة</TableHead>
                      <TableHead>الفرع</TableHead>
                    </TableRow>
                  )}
                </TableHeader>
                <TableBody>
                  {data.rows.data.map((r) =>
                    "person_code" in r ? (
                      <TableRow key={r.person_code} data-record={r.person_code}>
                        <TableCell><Link href={`/people/${r.person_code}`} className="font-medium hover:underline" dir="ltr">{r.person_code}</Link></TableCell>
                        <TableCell>{r.full_name}</TableCell>
                        <TableCell><Link href={`/families/${r.family_code}`} className="hover:underline" dir="ltr">{r.family_code}</Link></TableCell>
                        <TableCell>{r.branch ?? "غير محدد"}</TableCell>
                      </TableRow>
                    ) : (
                      <TableRow key={r.family_code} data-record={r.family_code}>
                        <TableCell><Link href={`/families/${r.family_code}`} className="font-medium hover:underline" dir="ltr">{r.family_code}</Link></TableCell>
                        <TableCell>{r.household_head ?? "—"}</TableCell>
                        <TableCell>{r.clan}</TableCell>
                        <TableCell>{r.branch ?? "غير محدد"}</TableCell>
                      </TableRow>
                    )
                  )}
                </TableBody>
              </Table>
            </div>
          ))}
          {data && <ReportPagination meta={data.rows.meta} onPage={onPage} />}
        </ReportState>
      </CardContent>
    </Card>
  );
}

export function DataQualityReport({
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
  const report = useReport<QualityData>("data-quality", scope);
  const data = report.data?.data;
  const selected = filters.issue as DataQualityIssueCode | undefined;

  const group = (name: "COMPLETENESS" | "CONSISTENCY", title: string, description: string) => (
    <Card size="sm">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {data?.issues.filter((i) => i.group === name).map((i) => (
          <button
            key={i.code}
            type="button"
            data-issue={i.code}
            disabled={i.count === 0}
            onClick={() => setFilters({ issue: i.code }, true)}
            className={cn(
              "flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-start transition-colors",
              i.count === 0 ? "opacity-60" : "hover:bg-muted/50",
              selected === i.code && "border-primary bg-muted/50",
            )}
          >
            <span className="flex flex-col gap-0.5">
              <span className="text-sm font-medium">{issueLabels[i.code].title}</span>
              <span className="text-xs text-muted-foreground">
                {i.count === 0 ? "لا توجد سجلات" : issueLabels[i.code].action(fmt(i.count))}
              </span>
            </span>
            <span className="flex shrink-0 items-center gap-1.5">
              <Badge variant={i.count === 0 ? "outline" : "secondary"} className="tabular-nums">{fmt(i.count)}</Badge>
              <Badge variant="outline" className="text-muted-foreground">{i.entity === "FAMILY" ? "أسرة" : "فرد"}</Badge>
              {i.count > 0 && <ChevronLeft className="size-4 text-muted-foreground" />}
            </span>
          </button>
        ))}
      </CardContent>
    </Card>
  );

  return (
    <ReportState isLoading={!data} error={report.error}>
      {data && (
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">
              فحوصات مشتقة من البيانات الحالية فقط. لا تُعرض القيم المفقودة أو الحساسة — فقط السجل الذي يحتاج تصحيحًا.
            </p>
            <ExportButton report="data-quality" params={scope} canExport={canExport} label="تصدير الملخص" />
          </div>
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {group("COMPLETENESS", "الاكتمال", "بيانات أساسية غير مستكملة")}
            <div className="flex flex-col gap-3">
              {group("CONSISTENCY", "الاتساق", "حالات يمكن إثباتها وتحتاج مراجعة")}
              <p className="flex items-center gap-1.5 px-1 text-xs text-muted-foreground" data-integrity>
                <ShieldCheck className="size-4" />
                تطابق فرع الأسرة مع عشيرتها مضمون بقيود قاعدة البيانات، لذلك لا يُعرض كفحص.
              </p>
            </div>
          </div>
          {selected && (
            <Records
              scope={scope}
              issue={selected}
              page={filters.page}
              onPage={(page) => setFilters({ page: String(page) })}
              onClose={() => setFilters({ issue: "" }, true)}
              canExport={canExport}
            />
          )}
        </div>
      )}
    </ReportState>
  );
}
