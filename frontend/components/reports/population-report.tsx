"use client";

import { useState } from "react";
import { ChevronDown, ChevronLeft } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { DetailItem, DetailList } from "@/components/shared/page-layout";
import { BarRow, ageBandLabels, fmt } from "@/components/dashboard/dashboard-sections";
import { EmptyNote, ExportButton, ReportSection, ReportState, ReportToolbar, StatStrip } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { PopulationReport as PopulationData } from "@/lib/types/api/reports";

type Group = NonNullable<PopulationData["organization"]>["groups"][number];

function GroupRows({ group, defaultOpen }: { group: Group; defaultOpen: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  const label = group.name ?? group.display_name ?? group.code;
  return (
    <>
      <TableRow className="border-stroke-subtle" data-group={group.code}>
        <TableCell className="ps-5 font-semibold whitespace-normal">
          <button
            type="button"
            onClick={() => setOpen(!open)}
            aria-expanded={open}
            className="flex items-center gap-1.5 rounded-sm text-start focus-visible:outline-2 focus-visible:outline-ring"
          >
            {open ? <ChevronDown className="size-4 text-muted-foreground" aria-hidden /> : <ChevronLeft className="size-4 text-muted-foreground" aria-hidden />}
            {label}
            {!group.is_active && <span className="text-xs font-normal text-muted-foreground">(غير مفعّلة)</span>}
          </button>
        </TableCell>
        <TableCell className="text-end tabular-nums">{fmt(group.families)}</TableCell>
        <TableCell className="pe-5 text-end tabular-nums">{fmt(group.people)}</TableCell>
      </TableRow>
      {open &&
        group.branches.map((b) => (
          <TableRow key={b.code} className="border-stroke-subtle bg-surface-2/60" data-branch={b.code}>
            <TableCell className="ps-11 whitespace-normal text-muted-foreground">
              {b.name}
              {!b.is_active && " (غير مفعّل)"}
            </TableCell>
            <TableCell className="text-end tabular-nums text-muted-foreground">{fmt(b.families)}</TableCell>
            <TableCell className="pe-5 text-end tabular-nums text-muted-foreground">{fmt(b.people)}</TableCell>
          </TableRow>
        ))}
    </>
  );
}

export function PopulationReport({ scope, canExport }: { scope: ReportParams; canExport: boolean }) {
  const report = useReport<PopulationData>("population", scope);
  const data = report.data?.data;

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        context="لا توجد مرشحات إضافية لهذا التقرير؛ يُحسب ضمن النطاق التنظيمي المحدد."
        exportAction={<ExportButton report="population" params={scope} canExport={canExport} />}
      />
      <ReportState isLoading={!data} error={report.error}>
        {data && (
          <>
            <StatStrip
              label="ملخص السكان"
              items={[
                { label: "الأسر النشطة", value: data.summary.active_families },
                { label: "الأفراد الحاليون", value: data.summary.current_people },
                { label: "ذكور", value: data.summary.male },
                { label: "إناث", value: data.summary.female },
                { label: "جنس غير محدد", value: data.summary.unknown_gender },
              ]}
            />

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
              <ReportSection title="الفئات العمرية" description="الأفراد الحاليون، محسوبة من تاريخ الميلاد حتى اليوم." data-section="age">
                <div className="flex flex-col gap-2.5">
                  {data.age_bands.map((b) => (
                    <BarRow
                      key={b.code}
                      label={ageBandLabels[b.code]}
                      count={b.count}
                      total={data.summary.current_people}
                      barClassName={b.code === "UNKNOWN" ? "bg-muted-foreground/40" : "bg-brand-700"}
                    />
                  ))}
                </div>
              </ReportSection>

              <ReportSection title="النزوح" description="حالة النزوح للأسر النشطة، وأماكن النزوح كما هي مسجّلة حرفيًا." data-section="displacement">
                <DetailList className="sm:grid-cols-1">
                  <DetailItem label="أسر نازحة">{fmt(data.summary.displaced)}</DetailItem>
                  <DetailItem label="أسر غير نازحة">{fmt(data.summary.not_displaced)}</DetailItem>
                  <DetailItem label="نزوح غير معروف / غير مسجّل">{fmt(data.summary.unknown_displacement)}</DetailItem>
                </DetailList>
                <h3 className="mt-4 mb-2 text-[13px] font-semibold text-foreground">أماكن النزوح</h3>
                {data.top_locations.length === 0 ? (
                  <EmptyNote>لا توجد أماكن نزوح مسجّلة ضمن النطاق.</EmptyNote>
                ) : (
                  <ul className="divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle text-sm">
                    {data.top_locations.map((l) => (
                      <li key={l.location} className="flex items-center justify-between gap-3 px-3 py-2">
                        <span className="min-w-0 truncate">{l.location}</span>
                        <span className="shrink-0 text-muted-foreground">
                          <bdi className="font-semibold text-foreground tabular-nums">{fmt(l.families)}</bdi> أسرة
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </ReportSection>
            </div>

            {data.organization && (
              <ReportSection
                title="التوزيع التنظيمي"
                description={data.organization.level === "CLAN" ? "مجموعات الفروع — افتح المجموعة لعرض فروعها." : "فروع المجموعة المختارة."}
                flush
                data-section="organization"
              >
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader className="bg-surface-1">
                      <TableRow className="border-stroke-subtle hover:bg-transparent">
                        <TableHead className="ps-5 text-xs text-muted-foreground">مجموعة الفروع / الفرع</TableHead>
                        <TableHead className="text-end text-xs text-muted-foreground">الأسر</TableHead>
                        <TableHead className="pe-5 text-end text-xs text-muted-foreground">الأفراد</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.organization.groups.map((g) => (
                        <GroupRows key={g.code} group={g} defaultOpen={data.organization?.level === "BRANCH_GROUP"} />
                      ))}
                      {data.organization.ungrouped && (
                        <GroupRows
                          group={{
                            code: "UNGROUPED",
                            name: "بدون مجموعة",
                            display_name: null,
                            is_active: true,
                            ...data.organization.ungrouped,
                          }}
                          defaultOpen={false}
                        />
                      )}
                      {data.organization.unassigned && (
                        <TableRow data-group="UNASSIGNED" className="border-stroke-subtle">
                          <TableCell className="ps-5 font-semibold whitespace-normal">
                            غير محدد <span className="text-xs font-normal text-muted-foreground">(أسر بلا فرع)</span>
                          </TableCell>
                          <TableCell className="text-end tabular-nums">{fmt(data.organization.unassigned.families)}</TableCell>
                          <TableCell className="pe-5 text-end tabular-nums">{fmt(data.organization.unassigned.people)}</TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </ReportSection>
            )}
          </>
        )}
      </ReportState>
    </div>
  );
}
