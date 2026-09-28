"use client";

import Link from "next/link";
import { PackageCheck, Send } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { IconBox } from "@/components/shared/icon-box";
import { fmt } from "@/components/dashboard/dashboard-sections";
import { AssistanceStatusTag, ExecutionModeTag } from "@/components/assistances/assistance-case";
import { FilterSelect } from "@/components/reports/filter-select";
import { EmptyNote, ExportButton, ReportPagination, ReportSection, ReportState, ReportToolbar } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { AssistanceProgramRow, AssistanceReport as AssistanceData } from "@/lib/types/api/reports";
import { ASSISTANCE_STATUSES, assistanceStatusLabels } from "@/lib/utils/assistance";
import { AppCard } from "@/components/shared/app-card";

const head = "h-10 text-xs font-medium text-muted-foreground";

function Figures({ p }: { p: AssistanceProgramRow }) {
  const f = (label: string, value: number) => (
    <span>
      {label}: <b className="font-semibold text-foreground tabular-nums">{fmt(value)}</b>
    </span>
  );
  if (p.internal) {
    return (
      <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground" data-figures="INTERNAL">
        {f("بانتظار التسليم", p.internal.awaiting_delivery)}
        {f("تم التسليم", p.internal.delivered)}
        {f("لم يتم التسليم", p.internal.not_delivered)}
        {f("تسليمات معكوسة", p.internal.reversed_deliveries)}
        {p.internal.delivery_percentage !== null && (
          <span>
            نسبة التسليم من المعتمدين: <b className="font-semibold text-foreground tabular-nums">{fmt(p.internal.delivery_percentage)}٪</b>
          </span>
        )}
      </span>
    );
  }
  if (p.external) {
    return (
      <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground" data-figures="EXTERNAL">
        {f("معتمدون لم يُصدروا في كشف", p.external.approved_not_listed)}
        {f("تم إصدارهم في كشوف", p.external.listed_unique)}
        {f("الكشوف الصادرة", p.external.issued_lists)}
      </span>
    );
  }
  return null;
}

/** One execution mode's totals: a titled figure list, not KPI tiles. */
function ModeTotals({
  mode,
  icon,
  title,
  note,
  items,
}: {
  mode: "INTERNAL" | "EXTERNAL";
  icon: typeof PackageCheck;
  title: string;
  note: React.ReactNode;
  items: { label: string; value: number }[];
}) {
  return (
    <AppCard padded={false} className="overflow-hidden" data-mode={mode}>
      <div className="flex items-start gap-3 px-4 pt-4 pb-3 sm:px-5">
        <IconBox icon={icon} size="sm" tone={mode === "INTERNAL" ? "brand" : "info"} />
        <div className="flex min-w-0 flex-col gap-0.5">
          <h3 className="flex flex-wrap items-center gap-2 text-base font-semibold">
            {title} <ExecutionModeTag mode={mode} />
          </h3>
          <p className="text-[13px] text-muted-foreground">{note}</p>
        </div>
      </div>
      <dl className="grid grid-cols-2 border-t border-stroke-subtle sm:grid-cols-3 [&>*]:border-stroke-subtle [&>*]:border-b [&>*:not(:nth-child(3n))]:sm:border-e max-sm:[&>*:nth-child(odd)]:border-e">
        {items.map((i) => (
          <div key={i.label} className="flex flex-col gap-0.5 px-4 py-2.5 sm:px-5" data-stat={i.label}>
            <dt className="text-xs text-muted-foreground">{i.label}</dt>
            <dd className="text-lg font-bold tabular-nums">
              <bdi>{fmt(i.value)}</bdi>
            </dd>
          </div>
        ))}
      </dl>
    </AppCard>
  );
}

export function AssistanceReport({
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
  const status = filters.status ?? "";
  const report = useReport<AssistanceData>("assistance", { ...scope, status, page: filters.page });
  const data = report.data?.data;

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        filters={
          <FilterSelect id="assistance-status" label="حالة المساعدة" value={status} onChange={(v) => setFilters({ status: v }, true)}
            options={ASSISTANCE_STATUSES.map((s) => ({ value: s, label: assistanceStatusLabels[s] }))} allLabel="مفتوحة ومكتملة" />
        }
        onReset={status ? () => setFilters({ status: "" }, true) : undefined}
        context={
          <>
            {status ? `الحالة: ${assistanceStatusLabels[status as keyof typeof assistanceStatusLabels]} · ` : "الحالة: مفتوحة ومكتملة · "}
            تُحتسب الأرقام للمستفيدين الذين تقع أسرهم المستهدفة ضمن النطاق فقط، وليست أرقام البرنامج الإجمالية.
          </>
        }
        exportAction={<ExportButton report="assistance" params={{ ...scope, status }} canExport={canExport} />}
      />

      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
              <ModeTotals
                mode="INTERNAL"
                icon={PackageCheck}
                title="تنفيذ داخلي"
                note={
                  <>
                    <bdi className="tabular-nums">{fmt(data.totals.internal.programs)}</bdi> برنامج — تسليم فعلي عبر Famboook
                  </>
                }
                items={[
                  { label: "معتمدون", value: data.totals.internal.approved },
                  { label: "بانتظار التسليم", value: data.totals.internal.awaiting_delivery },
                  { label: "تم التسليم", value: data.totals.internal.delivered },
                  { label: "لم يتم التسليم", value: data.totals.internal.not_delivered },
                  { label: "تسليمات معكوسة", value: data.totals.internal.reversed_deliveries },
                  { label: "مرفوضون", value: data.totals.internal.rejected },
                ]}
              />
              <ModeTotals
                mode="EXTERNAL"
                icon={Send}
                title="تنفيذ خارجي"
                note={
                  <>
                    <bdi className="tabular-nums">{fmt(data.totals.external.programs)}</bdi> برنامج — الإصدار في كشف لا يعني التسليم
                  </>
                }
                items={[
                  { label: "معتمدون", value: data.totals.external.approved },
                  { label: "لم يُصدروا في كشف", value: data.totals.external.approved_not_listed },
                  { label: "تم إصدارهم في كشوف", value: data.totals.external.listed_unique },
                  { label: "الكشوف الصادرة", value: data.totals.external.issued_lists },
                  { label: "مرفوضون", value: data.totals.external.rejected },
                ]}
              />
            </div>

            <ReportSection title="البرامج" description="برامج لها مستفيد واحد على الأقل ضمن النطاق." flush data-section="programs">
              {data.rows.data.length === 0 ? (
                <div className="px-4 py-6 text-center sm:px-5">
                  <EmptyNote>{status ? "لا توجد برامج مساعدة بهذه الحالة ضمن النطاق." : "لا توجد برامج مساعدة ضمن النطاق."}</EmptyNote>
                </div>
              ) : (
                <>
                  <Table className="hidden lg:table">
                    <TableHeader className="bg-surface-1">
                      <TableRow className="border-stroke-subtle hover:bg-transparent">
                        <TableHead className={`${head} ps-5`}>المساعدة</TableHead>
                        <TableHead className={head}>النمط / الحالة</TableHead>
                        <TableHead className={`${head} text-end`}>المستهدف</TableHead>
                        <TableHead className={`${head} text-end`}>مرشحون</TableHead>
                        <TableHead className={`${head} text-end`}>معتمدون</TableHead>
                        <TableHead className={`${head} text-end`}>مرفوضون</TableHead>
                        <TableHead className={`${head} pe-5`}>التنفيذ</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.rows.data.map((p) => (
                        <TableRow key={p.id} data-program={p.id} className="border-stroke-subtle align-top">
                          <TableCell className="max-w-72 ps-5 py-3">
                            <Link href={`/assistances/${p.id}`} className="block truncate rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                              {p.title}
                            </Link>
                            <p className="truncate text-xs text-muted-foreground">
                              {p.category.name} — {p.provider || "الجهة غير محددة"}
                            </p>
                          </TableCell>
                          <TableCell className="py-3">
                            <div className="flex flex-col items-start gap-1">
                              <ExecutionModeTag mode={p.execution_mode} />
                              <AssistanceStatusTag status={p.status} />
                            </div>
                          </TableCell>
                          <TableCell className="py-3 text-end tabular-nums">{p.target === null ? "—" : fmt(p.target)}</TableCell>
                          <TableCell className="py-3 text-end tabular-nums">{fmt(p.nominated)}</TableCell>
                          <TableCell className="py-3 text-end tabular-nums">{fmt(p.approved)}</TableCell>
                          <TableCell className="py-3 text-end tabular-nums">{fmt(p.rejected)}</TableCell>
                          <TableCell className="max-w-72 pe-5 py-3"><Figures p={p} /></TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                  <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="البرامج">
                    {data.rows.data.map((p) => (
                      <li key={p.id} className="flex flex-col gap-1.5 px-4 py-3" data-program={p.id}>
                        <div className="flex items-start justify-between gap-2">
                          <Link href={`/assistances/${p.id}`} className="min-w-0 rounded-sm font-semibold hover:underline">
                            {p.title}
                          </Link>
                          <AssistanceStatusTag status={p.status} />
                        </div>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <ExecutionModeTag mode={p.execution_mode} />
                          <span>{p.category.name}</span>
                          <span aria-hidden>·</span>
                          <span>
                            المستهدف {p.target === null ? "—" : fmt(p.target)} · مرشحون {fmt(p.nominated)} · معتمدون {fmt(p.approved)} · مرفوضون{" "}
                            {fmt(p.rejected)}
                          </span>
                        </span>
                        <Figures p={p} />
                      </li>
                    ))}
                  </ul>
                </>
              )}
              <ReportPagination meta={data.rows.meta} onPage={(page) => setFilters({ page: String(page) })} label="برنامج" />
            </ReportSection>
          </>
        )}
      </ReportState>
    </div>
  );
}
