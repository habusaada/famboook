"use client";

import Link from "next/link";
import { ChevronLeft, ShieldCheck, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { fmt } from "@/components/dashboard/dashboard-sections";
import { EmptyNote, ExportButton, ReportPagination, ReportSection, ReportState, ReportToolbar } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { DataQualityIssueCode, DataQualityRecordsReport, DataQualityReport as QualityData } from "@/lib/types/api/reports";
import { cn } from "@/lib/utils";

const head = "h-10 text-xs font-medium text-muted-foreground";

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
    <ReportSection
      title={issueLabels[issue].title}
      description="السجلات المتأثرة — التصحيح يتم من صفحة الأسرة أو الفرد."
      action={
        <div className="flex items-center gap-1">
          <ExportButton report="data-quality" params={{ ...scope, issue }} canExport={canExport} />
          <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="إغلاق قائمة السجلات">
            <X className="size-4" />
          </Button>
        </div>
      }
      flush
      data-records={issue}
    >
      <ReportState isLoading={!data} error={report.error}>
        {data &&
          (data.rows.data.length === 0 ? (
            <div className="px-4 py-6 text-center sm:px-5">
              <EmptyNote>لا توجد سجلات متأثرة ضمن النطاق.</EmptyNote>
            </div>
          ) : (
            <>
              <Table className="hidden md:table">
                <TableHeader className="bg-surface-1">
                  {data.entity === "FAMILY" ? (
                    <TableRow className="border-stroke-subtle hover:bg-transparent">
                      <TableHead className={`${head} ps-5`}>رقم الأسرة</TableHead>
                      <TableHead className={head}>رب الأسرة</TableHead>
                      <TableHead className={head}>العشيرة / العائلة</TableHead>
                      <TableHead className={`${head} pe-5`}>الفرع</TableHead>
                    </TableRow>
                  ) : (
                    <TableRow className="border-stroke-subtle hover:bg-transparent">
                      <TableHead className={`${head} ps-5`}>رقم الفرد</TableHead>
                      <TableHead className={head}>الاسم</TableHead>
                      <TableHead className={head}>رقم الأسرة</TableHead>
                      <TableHead className={`${head} pe-5`}>الفرع</TableHead>
                    </TableRow>
                  )}
                </TableHeader>
                <TableBody>
                  {data.rows.data.map((r) =>
                    "person_code" in r ? (
                      <TableRow key={r.person_code} data-record={r.person_code} className="border-stroke-subtle">
                        <TableCell className="ps-5">
                          <Link href={`/people/${r.person_code}`} className="rounded-sm font-semibold text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            <Code>{r.person_code}</Code>
                          </Link>
                        </TableCell>
                        <TableCell>{r.full_name}</TableCell>
                        <TableCell>
                          <Link href={`/families/${r.family_code}`} className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            <Code>{r.family_code}</Code>
                          </Link>
                        </TableCell>
                        <TableCell className={cn("pe-5", !r.branch && "text-muted-foreground")}>{r.branch ?? "غير محدد"}</TableCell>
                      </TableRow>
                    ) : (
                      <TableRow key={r.family_code} data-record={r.family_code} className="border-stroke-subtle">
                        <TableCell className="ps-5">
                          <Link href={`/families/${r.family_code}`} className="rounded-sm font-semibold text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            <Code>{r.family_code}</Code>
                          </Link>
                        </TableCell>
                        <TableCell>{r.household_head ?? "—"}</TableCell>
                        <TableCell>{r.clan}</TableCell>
                        <TableCell className={cn("pe-5", !r.branch && "text-muted-foreground")}>{r.branch ?? "غير محدد"}</TableCell>
                      </TableRow>
                    )
                  )}
                </TableBody>
              </Table>
              <ul className="divide-y divide-stroke-subtle md:hidden" aria-label="السجلات المتأثرة">
                {data.rows.data.map((r) =>
                  "person_code" in r ? (
                    <li key={r.person_code} className="flex flex-col gap-1 px-4 py-3" data-record={r.person_code}>
                      <Link href={`/people/${r.person_code}`} className="w-fit rounded-sm font-semibold hover:underline">
                        {r.full_name}
                      </Link>
                      <span className="text-xs text-muted-foreground">
                        <Code className="text-brand-800">{r.person_code}</Code> · <Code>{r.family_code}</Code> · {r.branch ?? "غير محدد"}
                      </span>
                    </li>
                  ) : (
                    <li key={r.family_code} className="flex flex-col gap-1 px-4 py-3" data-record={r.family_code}>
                      <Link href={`/families/${r.family_code}`} className="w-fit rounded-sm font-semibold text-brand-800 hover:underline">
                        <Code>{r.family_code}</Code>
                      </Link>
                      <span className="text-xs text-muted-foreground">
                        {r.household_head ?? "—"} · {r.branch ?? "غير محدد"}
                      </span>
                    </li>
                  )
                )}
              </ul>
            </>
          ))}
        {data && <ReportPagination meta={data.rows.meta} onPage={onPage} />}
      </ReportState>
    </ReportSection>
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
    <ReportSection title={title} description={description} flush data-section={name}>
      <ul className="divide-y divide-stroke-subtle">
        {data?.issues
          .filter((i) => i.group === name)
          .map((i) => (
            <li key={i.code}>
              <button
                type="button"
                data-issue={i.code}
                disabled={i.count === 0}
                aria-current={selected === i.code ? "true" : undefined}
                onClick={() => setFilters({ issue: i.code }, true)}
                className={cn(
                  "flex w-full items-center justify-between gap-3 px-4 py-3 text-start transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5",
                  i.count === 0 ? "cursor-default" : "hover:bg-surface-hover",
                  selected === i.code && "bg-surface-selected"
                )}
              >
                <span className="flex min-w-0 flex-col gap-0.5">
                  <span className={cn("text-sm", i.count === 0 ? "text-muted-foreground" : "font-semibold text-foreground")}>{issueLabels[i.code].title}</span>
                  <span className="text-xs text-muted-foreground">{i.count === 0 ? "لا توجد سجلات" : issueLabels[i.code].action(fmt(i.count))}</span>
                </span>
                <span className="flex shrink-0 items-center gap-1.5">
                  <bdi className={cn("min-w-8 text-end text-base font-bold tabular-nums", i.count === 0 && "text-muted-foreground")}>{fmt(i.count)}</bdi>
                  <StatusBadge tone="neutral">{i.entity === "FAMILY" ? "أسرة" : "فرد"}</StatusBadge>
                  {i.count > 0 && <ChevronLeft className="size-4 text-muted-foreground" aria-hidden />}
                </span>
              </button>
            </li>
          ))}
      </ul>
    </ReportSection>
  );

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        context={
          <>
            <ShieldCheck className="size-4 shrink-0" aria-hidden />
            فحوصات مشتقة من البيانات الحالية فقط. لا تُعرض القيم المفقودة أو الحساسة — فقط السجل الذي يحتاج تصحيحًا.
          </>
        }
        exportAction={<ExportButton report="data-quality" params={scope} canExport={canExport} label="تصدير الملخص" />}
      />
      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
              {group("COMPLETENESS", "الاكتمال", "بيانات أساسية غير مستكملة.")}
              <div className="flex flex-col gap-3">
                {group("CONSISTENCY", "الاتساق", "حالات يمكن إثباتها وتحتاج مراجعة.")}
                <p className="flex items-center gap-1.5 px-1 text-xs text-muted-foreground" data-integrity>
                  <ShieldCheck className="size-4 shrink-0" aria-hidden />
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
          </>
        )}
      </ReportState>
    </div>
  );
}
