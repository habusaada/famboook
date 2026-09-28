"use client";

import { ShieldCheck } from "lucide-react";
import { BarRow } from "@/components/dashboard/dashboard-sections";
import { EmptyNote, ExportButton, ReportSection, ReportState, ReportToolbar, StatStrip } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { HealthReport as HealthData } from "@/lib/types/api/reports";

/** Aggregate only: V1 has deliberately no person-level health drill-down. */
export function HealthReport({ scope, canExport }: { scope: ReportParams; canExport: boolean }) {
  const report = useReport<HealthData>("health", scope);
  const data = report.data?.data;
  const h = data?.health;

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        context={
          <>
            <ShieldCheck className="size-4 shrink-0" aria-hidden />
            تقرير إجمالي فقط: لا أسماء أمراض ولا تفاصيل صحية ولا قوائم أفراد.
          </>
        }
        exportAction={<ExportButton report="health" params={scope} canExport={canExport} />}
      />
      <ReportState isLoading={!data} error={report.error}>
        {h && (
          <>
            <StatStrip
              label="ملخص الحالات الصحية النشطة"
              items={[
                { label: "ذوو إعاقة", value: h.people_with_disability, hint: "أفراد بحالة نشطة" },
                { label: "أمراض مزمنة", value: h.people_with_chronic_disease, hint: "أفراد بحالة نشطة" },
                { label: "حمل نشط", value: h.active_pregnancy },
                { label: "رضاعة نشطة", value: h.active_breastfeeding },
              ]}
            />
            <ReportSection
              title="الإعاقة حسب النوع"
              description="عدد الأفراد المميزين لكل نوع (قد يُحتسب الفرد في أكثر من نوع)."
              data-section="disability-types"
            >
              {h.disability_types.length === 0 ? (
                <EmptyNote>لا توجد إعاقات نشطة ضمن النطاق.</EmptyNote>
              ) : (
                <div className="flex flex-col gap-2.5">
                  {h.disability_types.map((t) => (
                    <BarRow key={t.code ?? "none"} label={t.name ?? "غير محدد"} count={t.people} total={h.people_with_disability} barClassName="bg-brand-700" />
                  ))}
                </div>
              )}
            </ReportSection>
          </>
        )}
      </ReportState>
    </div>
  );
}
