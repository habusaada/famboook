"use client";

import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyNote, StatTile, fmt } from "@/components/dashboard/dashboard-sections";
import { FilterSelect } from "@/components/reports/filter-select";
import { ExportButton, ReportPagination, ReportState } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { AssistanceProgramRow, AssistanceReport as AssistanceData } from "@/lib/types/api/reports";
import { ASSISTANCE_STATUSES, assistanceStatusLabels, executionModeShort } from "@/lib/utils/assistance";

function Figures({ p }: { p: AssistanceProgramRow }) {
  if (p.internal) {
    return (
      <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs" data-figures="INTERNAL">
        <span>بانتظار التسليم: <b className="tabular-nums">{fmt(p.internal.awaiting_delivery)}</b></span>
        <span>تم التسليم: <b className="tabular-nums">{fmt(p.internal.delivered)}</b></span>
        <span>لم يتم التسليم: <b className="tabular-nums">{fmt(p.internal.not_delivered)}</b></span>
        <span>تسليمات معكوسة: <b className="tabular-nums">{fmt(p.internal.reversed_deliveries)}</b></span>
        {p.internal.delivery_percentage !== null && (
          <span>نسبة التسليم من المعتمدين: <b className="tabular-nums">{fmt(p.internal.delivery_percentage)}٪</b></span>
        )}
      </span>
    );
  }
  if (p.external) {
    return (
      <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs" data-figures="EXTERNAL">
        <span>معتمدون لم يُصدروا في كشف: <b className="tabular-nums">{fmt(p.external.approved_not_listed)}</b></span>
        <span>تم إصدارهم في كشوف: <b className="tabular-nums">{fmt(p.external.listed_unique)}</b></span>
        <span>الكشوف الصادرة: <b className="tabular-nums">{fmt(p.external.issued_lists)}</b></span>
      </span>
    );
  }
  return null;
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
      <div className="flex flex-wrap items-end gap-3">
        <FilterSelect id="assistance-status" label="حالة المساعدة" value={status} onChange={(v) => setFilters({ status: v }, true)}
          options={ASSISTANCE_STATUSES.map((s) => ({ value: s, label: assistanceStatusLabels[s] }))} allLabel="مفتوحة ومكتملة" />
        <div className="ms-auto">
          <ExportButton report="assistance" params={{ ...scope, status }} canExport={canExport} />
        </div>
      </div>

      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <p className="text-xs text-muted-foreground">
              تُحتسب الأرقام للمستفيدين الذين تقع أسرهم المستهدفة ضمن النطاق فقط، وليست أرقام البرنامج الإجمالية.
            </p>
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
              <Card size="sm" data-mode="INTERNAL">
                <CardHeader>
                  <CardTitle>تنفيذ داخلي</CardTitle>
                  <CardDescription>{fmt(data.totals.internal.programs)} برنامج — تسليم فعلي عبر Famboook</CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-3 gap-2">
                  <StatTile label="معتمدون" value={data.totals.internal.approved} />
                  <StatTile label="بانتظار التسليم" value={data.totals.internal.awaiting_delivery} />
                  <StatTile label="تم التسليم" value={data.totals.internal.delivered} />
                  <StatTile label="لم يتم التسليم" value={data.totals.internal.not_delivered} />
                  <StatTile label="تسليمات معكوسة" value={data.totals.internal.reversed_deliveries} />
                  <StatTile label="مرفوضون" value={data.totals.internal.rejected} />
                </CardContent>
              </Card>
              <Card size="sm" data-mode="EXTERNAL">
                <CardHeader>
                  <CardTitle>تنفيذ خارجي</CardTitle>
                  <CardDescription>{fmt(data.totals.external.programs)} برنامج — الإصدار في كشف لا يعني التسليم</CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-3 gap-2">
                  <StatTile label="معتمدون" value={data.totals.external.approved} />
                  <StatTile label="لم يُصدروا في كشف" value={data.totals.external.approved_not_listed} />
                  <StatTile label="تم إصدارهم في كشوف" value={data.totals.external.listed_unique} />
                  <StatTile label="الكشوف الصادرة" value={data.totals.external.issued_lists} />
                  <StatTile label="مرفوضون" value={data.totals.external.rejected} />
                </CardContent>
              </Card>
            </div>

            <Card size="sm">
              <CardHeader>
                <CardTitle>البرامج</CardTitle>
                <CardDescription>برامج لها مستفيد واحد على الأقل ضمن النطاق</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {data.rows.data.length === 0 ? (
                  <EmptyNote>لا توجد برامج مساعدة ضمن النطاق والحالة المحددة.</EmptyNote>
                ) : (
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>المساعدة</TableHead>
                          <TableHead>النمط</TableHead>
                          <TableHead>الحالة</TableHead>
                          <TableHead>المستهدف</TableHead>
                          <TableHead>مرشحون</TableHead>
                          <TableHead>معتمدون</TableHead>
                          <TableHead>مرفوضون</TableHead>
                          <TableHead>التنفيذ</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.rows.data.map((p) => (
                          <TableRow key={p.id} data-program={p.id}>
                            <TableCell>
                              <Link href={`/assistances/${p.id}`} className="font-medium hover:underline">{p.title}</Link>
                              <p className="text-xs text-muted-foreground">{p.category.name} — {p.provider}</p>
                            </TableCell>
                            <TableCell><Badge variant="secondary">{executionModeShort[p.execution_mode]}</Badge></TableCell>
                            <TableCell>{assistanceStatusLabels[p.status]}</TableCell>
                            <TableCell className="tabular-nums">{p.target === null ? "—" : fmt(p.target)}</TableCell>
                            <TableCell className="tabular-nums">{fmt(p.nominated)}</TableCell>
                            <TableCell className="tabular-nums">{fmt(p.approved)}</TableCell>
                            <TableCell className="tabular-nums">{fmt(p.rejected)}</TableCell>
                            <TableCell><Figures p={p} /></TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                )}
                <ReportPagination meta={data.rows.meta} onPage={(page) => setFilters({ page: String(page) })} label="برنامج" />
              </CardContent>
            </Card>
          </>
        )}
      </ReportState>
    </div>
  );
}
