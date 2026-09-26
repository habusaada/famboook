"use client";

import { ShieldCheck } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { BarRow, EmptyNote, StatTile } from "@/components/dashboard/dashboard-sections";
import { ExportButton, ReportState } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { HealthReport as HealthData } from "@/lib/types/api/reports";

/** Aggregate only: V1 has deliberately no person-level health drill-down. */
export function HealthReport({ scope, canExport }: { scope: ReportParams; canExport: boolean }) {
  const report = useReport<HealthData>("health", scope);
  const data = report.data?.data;
  const h = data?.health;

  return (
    <ReportState isLoading={!data} error={report.error}>
      {h && (
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <ShieldCheck className="size-4" />
              تقرير إجمالي فقط: لا أسماء أمراض ولا تفاصيل صحية ولا قوائم أفراد.
            </p>
            <ExportButton report="health" params={scope} canExport={canExport} />
          </div>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <StatTile label="ذوو إعاقة" value={h.people_with_disability} hint="أفراد بحالة نشطة" />
            <StatTile label="أمراض مزمنة" value={h.people_with_chronic_disease} hint="أفراد بحالة نشطة" />
            <StatTile label="حمل نشط" value={h.active_pregnancy} />
            <StatTile label="رضاعة نشطة" value={h.active_breastfeeding} />
          </div>
          <Card size="sm">
            <CardHeader>
              <CardTitle>الإعاقة حسب النوع</CardTitle>
              <CardDescription>عدد الأفراد المميزين لكل نوع (قد يُحتسب الفرد في أكثر من نوع)</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2.5">
              {h.disability_types.length === 0 ? (
                <EmptyNote>لا توجد إعاقات نشطة ضمن النطاق.</EmptyNote>
              ) : (
                h.disability_types.map((t) => (
                  <BarRow key={t.code ?? "none"} label={t.name ?? "غير محدد"} count={t.people} total={h.people_with_disability} />
                ))
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </ReportState>
  );
}
