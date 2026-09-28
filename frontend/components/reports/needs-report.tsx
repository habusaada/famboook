"use client";

import Link from "next/link";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Code } from "@/components/shared/page-layout";
import { BarRow, fmt } from "@/components/dashboard/dashboard-sections";
import { NeedPriorityTag, NeedStatusTag } from "@/components/needs/need-case";
import { FilterSelect } from "@/components/reports/filter-select";
import {
  EmptyNote,
  ExportButton,
  ReportPagination,
  ReportSection,
  ReportState,
  ReportToolbar,
  StatStrip,
} from "@/components/reports/report-parts";
import { useNeedCategories } from "@/lib/api/reference";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { NeedReportRow, NeedsReport as NeedsData } from "@/lib/types/api/reports";
import { NEED_PRIORITIES, NEED_STATUSES, needPriorityLabels, needStatusLabels } from "@/lib/utils/need";

const TARGET_LABELS = { FAMILY: "الأسرة", PERSON: "فرد" } as const;
const FILTER_KEYS = ["status", "priority", "category", "target"] as const;
const head = "h-10 text-xs font-medium text-muted-foreground";

function TargetCell({ n }: { n: NeedReportRow }) {
  return (
    <span>
      <span className="text-xs text-muted-foreground">{TARGET_LABELS[n.target_type]}: </span>
      {n.target_type === "PERSON" ? (
        <Link href={`/people/${n.target.code}`} className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring">
          {n.target.name}
        </Link>
      ) : (
        (n.target.name ?? "—")
      )}
    </span>
  );
}

export function NeedsReport({
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
  const reportFilters = Object.fromEntries(FILTER_KEYS.map((k) => [k, filters[k] ?? ""]));
  const params = { ...scope, ...reportFilters, page: filters.page };
  const report = useReport<NeedsData>("needs", params);
  const categories = useNeedCategories();
  const data = report.data?.data;
  const hasFilters = FILTER_KEYS.some((k) => filters[k]);
  const categoryName = (code: string) => categories.data?.data.find((c) => c.code === code)?.name ?? code;
  const active = [
    reportFilters.status && `الحالة: ${needStatusLabels[reportFilters.status as keyof typeof needStatusLabels]}`,
    reportFilters.priority && `الأولوية: ${needPriorityLabels[reportFilters.priority as keyof typeof needPriorityLabels]}`,
    reportFilters.category && `الفئة: ${categoryName(reportFilters.category)}`,
    reportFilters.target && `المستهدف: ${TARGET_LABELS[reportFilters.target as keyof typeof TARGET_LABELS]}`,
  ].filter(Boolean) as string[];

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        filters={
          <>
            <FilterSelect id="needs-status" label="الحالة" value={reportFilters.status} onChange={(v) => setFilters({ status: v }, true)}
              options={NEED_STATUSES.map((s) => ({ value: s, label: needStatusLabels[s] }))} allLabel="جميع الحالات" />
            <FilterSelect id="needs-priority" label="الأولوية" value={reportFilters.priority} onChange={(v) => setFilters({ priority: v }, true)}
              options={[...NEED_PRIORITIES].reverse().map((p) => ({ value: p, label: needPriorityLabels[p] }))} />
            <FilterSelect id="needs-category" label="الفئة" value={reportFilters.category} onChange={(v) => setFilters({ category: v }, true)}
              options={(categories.data?.data ?? []).map((c) => ({ value: c.code, label: c.name }))} />
            <FilterSelect id="needs-target" label="نوع المستهدف" value={reportFilters.target} onChange={(v) => setFilters({ target: v }, true)}
              options={[{ value: "FAMILY", label: "الأسرة" }, { value: "PERSON", label: "فرد" }]} />
          </>
        }
        onReset={hasFilters ? () => setFilters({ status: "", priority: "", category: "", target: "" }, true) : undefined}
        context={active.length > 0 ? <>المرشحات النشطة: {active.join(" · ")}</> : "جميع الاحتياجات ضمن النطاق (الحالية والتاريخية)."}
        exportAction={<ExportButton report="needs" params={{ ...scope, ...reportFilters }} canExport={canExport} />}
      />

      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <StatStrip
              label="ملخص الاحتياجات"
              items={[
                { label: "إجمالي الاحتياجات", value: data.summary.total, hint: "ضمن المرشحات المحددة" },
                { label: "مفتوحة (حالية)", value: data.summary.open },
                { label: "تمت تلبيتها", value: data.summary.fulfilled, hint: "سجل تاريخي" },
                { label: "مغلقة دون تلبية", value: data.summary.closed, hint: "سجل تاريخي" },
              ]}
            />

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
              <ReportSection title="حسب الأولوية" data-section="by-priority">
                <div className="flex flex-col gap-2.5">
                  {data.summary.by_priority.map((p) => (
                    <BarRow key={p.priority} label={needPriorityLabels[p.priority]} count={p.count} total={data.summary.total} barClassName="bg-brand-700" />
                  ))}
                </div>
              </ReportSection>
              <ReportSection title="حسب الفئة" data-section="by-category">
                {data.summary.by_category.length === 0 ? (
                  <EmptyNote>لا توجد احتياجات.</EmptyNote>
                ) : (
                  <div className="flex flex-col gap-2.5">
                    {data.summary.by_category.map((c) => (
                      <BarRow key={c.code} label={c.name} count={c.count} total={data.summary.total} barClassName="bg-brand-700" />
                    ))}
                  </div>
                )}
              </ReportSection>
              <ReportSection title="حسب نوع المستهدف" data-section="by-target">
                <div className="flex flex-col gap-2.5">
                  <BarRow label="الأسرة" count={data.summary.by_target.family} total={data.summary.total} barClassName="bg-brand-700" />
                  <BarRow label="فرد" count={data.summary.by_target.person} total={data.summary.total} barClassName="bg-brand-700" />
                </div>
              </ReportSection>
            </div>

            <ReportSection
              title="تفاصيل الاحتياجات"
              description="بدون وصف الاحتياج أو سبب الإغلاق — افتح الاحتياج للتفاصيل المصرّح بها."
              flush
              data-section="rows"
            >
              {data.rows.data.length === 0 ? (
                <div className="px-4 py-6 text-center sm:px-5">
                  <EmptyNote>{hasFilters ? "لا توجد احتياجات مطابقة للمرشحات المحددة." : "لا توجد احتياجات ضمن النطاق."}</EmptyNote>
                </div>
              ) : (
                <>
                  <Table className="hidden lg:table">
                    <TableHeader className="bg-surface-1">
                      <TableRow className="border-stroke-subtle hover:bg-transparent">
                        <TableHead className={`${head} ps-5`}>الاحتياج</TableHead>
                        <TableHead className={head}>الأولوية</TableHead>
                        <TableHead className={head}>الحالة</TableHead>
                        <TableHead className={head}>المستهدف</TableHead>
                        <TableHead className={head}>الأسرة</TableHead>
                        <TableHead className={head}>الإنشاء</TableHead>
                        <TableHead className={`${head} pe-5`}>الإغلاق / التلبية</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.rows.data.map((n) => (
                        <TableRow key={n.id} data-need={n.id} className="border-stroke-subtle">
                          <TableCell className="max-w-72 ps-5">
                            <Link href={`/needs/${n.id}`} className="block truncate rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                              {n.title}
                            </Link>
                            <span className="text-xs text-muted-foreground">{n.category.name}</span>
                          </TableCell>
                          <TableCell><NeedPriorityTag priority={n.priority} /></TableCell>
                          <TableCell><NeedStatusTag status={n.status} /></TableCell>
                          <TableCell className="max-w-56"><TargetCell n={n} /></TableCell>
                          <TableCell>
                            <Link href={`/families/${n.family_code}`} className="rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                              <Code>{n.family_code}</Code>
                            </Link>
                          </TableCell>
                          <TableCell className="text-[13px]"><bdi dir="ltr" className="tabular-nums">{n.created_date ?? "—"}</bdi></TableCell>
                          <TableCell className="pe-5 text-[13px]"><bdi dir="ltr" className="tabular-nums">{n.resolved_date ?? "—"}</bdi></TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                  <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="تفاصيل الاحتياجات">
                    {data.rows.data.map((n) => (
                      <li key={n.id} className="flex flex-col gap-1.5 px-4 py-3" data-need={n.id}>
                        <div className="flex items-start justify-between gap-2">
                          <Link href={`/needs/${n.id}`} className="min-w-0 rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                            {n.title}
                          </Link>
                          <NeedPriorityTag priority={n.priority} />
                        </div>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <NeedStatusTag status={n.status} />
                          <span>{n.category.name}</span>
                          <span aria-hidden>·</span>
                          <Code className="text-brand-800">{n.family_code}</Code>
                        </span>
                        <span className="text-xs text-muted-foreground">
                          <TargetCell n={n} /> · أُنشئ <bdi dir="ltr" className="tabular-nums">{n.created_date ?? "—"}</bdi>
                          {n.resolved_date && (
                            <>
                              {" "}· أُغلق <bdi dir="ltr" className="tabular-nums">{n.resolved_date}</bdi>
                            </>
                          )}
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
              <ReportPagination meta={data.rows.meta} onPage={(page) => setFilters({ page: String(page) })} label="احتياج" />
            </ReportSection>
            <p className="text-xs text-muted-foreground">
              <bdi className="tabular-nums">{fmt(data.summary.open)}</bdi> احتياج حالي مفتوح؛ الباقي سجلات تاريخية.
            </p>
          </>
        )}
      </ReportState>
    </div>
  );
}
