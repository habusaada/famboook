"use client";

import Link from "next/link";
import { RotateCcw } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { BarRow, EmptyNote, StatTile, fmt } from "@/components/dashboard/dashboard-sections";
import { FilterSelect } from "@/components/reports/filter-select";
import { ExportButton, ReportPagination, ReportState } from "@/components/reports/report-parts";
import { useNeedCategories } from "@/lib/api/reference";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { NeedsReport as NeedsData } from "@/lib/types/api/reports";
import { NEED_PRIORITIES, NEED_STATUSES, needPriorityLabels, needPriorityStyles, needStatusLabels } from "@/lib/utils/need";

const TARGET_LABELS = { FAMILY: "الأسرة", PERSON: "فرد" } as const;
const FILTER_KEYS = ["status", "priority", "category", "target"] as const;

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

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <FilterSelect id="needs-status" label="الحالة" value={reportFilters.status} onChange={(v) => setFilters({ status: v }, true)}
          options={NEED_STATUSES.map((s) => ({ value: s, label: needStatusLabels[s] }))} allLabel="جميع الحالات" />
        <FilterSelect id="needs-priority" label="الأولوية" value={reportFilters.priority} onChange={(v) => setFilters({ priority: v }, true)}
          options={[...NEED_PRIORITIES].reverse().map((p) => ({ value: p, label: needPriorityLabels[p] }))} />
        <FilterSelect id="needs-category" label="الفئة" value={reportFilters.category} onChange={(v) => setFilters({ category: v }, true)}
          options={(categories.data?.data ?? []).map((c) => ({ value: c.code, label: c.name }))} />
        <FilterSelect id="needs-target" label="نوع المستهدف" value={reportFilters.target} onChange={(v) => setFilters({ target: v }, true)}
          options={[{ value: "FAMILY", label: "الأسرة" }, { value: "PERSON", label: "فرد" }]} />
        {hasFilters && (
          <Button variant="ghost" size="sm" onClick={() => setFilters({ status: "", priority: "", category: "", target: "" }, true)}>
            <RotateCcw className="size-4" />
            إعادة الضبط
          </Button>
        )}
        <div className="ms-auto">
          <ExportButton report="needs" params={{ ...scope, ...reportFilters }} canExport={canExport} />
        </div>
      </div>

      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
              <StatTile label="إجمالي الاحتياجات" value={data.summary.total} hint="ضمن المرشحات المحددة" />
              <StatTile label="مفتوحة (حالية)" value={data.summary.open} />
              <StatTile label="تمت تلبيتها" value={data.summary.fulfilled} hint="سجل تاريخي" />
              <StatTile label="مغلقة دون تلبية" value={data.summary.closed} hint="سجل تاريخي" />
            </div>

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
              <Card size="sm">
                <CardHeader><CardTitle>حسب الأولوية</CardTitle></CardHeader>
                <CardContent className="flex flex-col gap-2.5">
                  {data.summary.by_priority.map((p) => (
                    <BarRow key={p.priority} label={needPriorityLabels[p.priority]} count={p.count} total={data.summary.total} />
                  ))}
                </CardContent>
              </Card>
              <Card size="sm">
                <CardHeader><CardTitle>حسب الفئة</CardTitle></CardHeader>
                <CardContent className="flex flex-col gap-2.5">
                  {data.summary.by_category.length === 0 ? <EmptyNote>لا توجد احتياجات.</EmptyNote> :
                    data.summary.by_category.map((c) => <BarRow key={c.code} label={c.name} count={c.count} total={data.summary.total} />)}
                </CardContent>
              </Card>
              <Card size="sm">
                <CardHeader><CardTitle>حسب نوع المستهدف</CardTitle></CardHeader>
                <CardContent className="flex flex-col gap-2.5">
                  <BarRow label="الأسرة" count={data.summary.by_target.family} total={data.summary.total} />
                  <BarRow label="فرد" count={data.summary.by_target.person} total={data.summary.total} />
                </CardContent>
              </Card>
            </div>

            <Card size="sm">
              <CardHeader>
                <CardTitle>تفاصيل الاحتياجات</CardTitle>
                <CardDescription>بدون وصف الاحتياج أو سبب الإغلاق — افتح الاحتياج للتفاصيل المصرّح بها</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {data.rows.data.length === 0 ? (
                  <EmptyNote>لا توجد احتياجات مطابقة.</EmptyNote>
                ) : (
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>العنوان</TableHead>
                          <TableHead>الفئة</TableHead>
                          <TableHead>الأولوية</TableHead>
                          <TableHead>الحالة</TableHead>
                          <TableHead>المستهدف</TableHead>
                          <TableHead>الأسرة</TableHead>
                          <TableHead>الإنشاء</TableHead>
                          <TableHead>الإغلاق / التلبية</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.rows.data.map((n) => (
                          <TableRow key={n.id} data-need={n.id}>
                            <TableCell className="font-medium">
                              <Link href={`/needs/${n.id}`} className="hover:underline">{n.title}</Link>
                            </TableCell>
                            <TableCell>{n.category.name}</TableCell>
                            <TableCell><Badge variant="outline" className={needPriorityStyles[n.priority]}>{needPriorityLabels[n.priority]}</Badge></TableCell>
                            <TableCell>{needStatusLabels[n.status]}</TableCell>
                            <TableCell>
                              <span className="text-xs text-muted-foreground">{TARGET_LABELS[n.target_type]}: </span>
                              {n.target_type === "PERSON" ? (
                                <Link href={`/people/${n.target.code}`} className="hover:underline">{n.target.name}</Link>
                              ) : (
                                n.target.name ?? "—"
                              )}
                            </TableCell>
                            <TableCell>
                              <Link href={`/families/${n.family_code}`} className="hover:underline" dir="ltr">{n.family_code}</Link>
                            </TableCell>
                            <TableCell className="tabular-nums" dir="ltr">{n.created_date}</TableCell>
                            <TableCell className="tabular-nums" dir="ltr">{n.resolved_date ?? "—"}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                )}
                <ReportPagination meta={data.rows.meta} onPage={(page) => setFilters({ page: String(page) })} label="احتياج" />
              </CardContent>
            </Card>
            <p className="text-xs text-muted-foreground">{fmt(data.summary.open)} احتياج حالي مفتوح؛ الباقي سجلات تاريخية.</p>
          </>
        )}
      </ReportState>
    </div>
  );
}
