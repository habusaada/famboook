"use client";

import { useState } from "react";
import { ChevronDown, ChevronLeft } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { BarRow, EmptyNote, StatTile, ageBandLabels, fmt } from "@/components/dashboard/dashboard-sections";
import { ExportButton, ReportState } from "@/components/reports/report-parts";
import { useReport, type ReportParams } from "@/lib/api/reports";
import type { PopulationReport as PopulationData } from "@/lib/types/api/reports";

type Group = NonNullable<PopulationData["organization"]>["groups"][number];

function GroupRows({ group, defaultOpen }: { group: Group; defaultOpen: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  const label = group.name ?? group.display_name ?? group.code;
  return (
    <>
      <TableRow className="cursor-pointer" onClick={() => setOpen(!open)} data-group={group.code}>
        <TableCell className="font-medium">
          <span className="flex items-center gap-1.5">
            {open ? <ChevronDown className="size-4 text-muted-foreground" /> : <ChevronLeft className="size-4 text-muted-foreground" />}
            {label}
            {!group.is_active && <span className="text-xs text-muted-foreground">(غير مفعّلة)</span>}
          </span>
        </TableCell>
        <TableCell className="tabular-nums">{fmt(group.families)}</TableCell>
        <TableCell className="tabular-nums">{fmt(group.people)}</TableCell>
      </TableRow>
      {open &&
        group.branches.map((b) => (
          <TableRow key={b.code} className="bg-muted/30" data-branch={b.code}>
            <TableCell className="ps-10 text-muted-foreground">
              {b.name}
              {!b.is_active && " (غير مفعّل)"}
            </TableCell>
            <TableCell className="tabular-nums text-muted-foreground">{fmt(b.families)}</TableCell>
            <TableCell className="tabular-nums text-muted-foreground">{fmt(b.people)}</TableCell>
          </TableRow>
        ))}
    </>
  );
}

export function PopulationReport({ scope, canExport }: { scope: ReportParams; canExport: boolean }) {
  const report = useReport<PopulationData>("population", scope);
  const data = report.data?.data;

  return (
    <ReportState isLoading={!data} error={report.error}>
      {data && (
        <div className="flex flex-col gap-4">
          <div className="flex justify-end">
            <ExportButton report="population" params={scope} canExport={canExport} />
          </div>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <StatTile label="الأسر النشطة" value={data.summary.active_families} />
            <StatTile label="الأفراد الحاليون" value={data.summary.current_people} />
            <StatTile label="ذكور" value={data.summary.male} />
            <StatTile label="إناث" value={data.summary.female} />
            <StatTile label="جنس غير محدد" value={data.summary.unknown_gender} />
            <StatTile label="أسر نازحة" value={data.summary.displaced} />
            <StatTile label="أسر غير نازحة" value={data.summary.not_displaced} />
            <StatTile label="نزوح غير معروف / غير مسجّل" value={data.summary.unknown_displacement} />
          </div>

          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <Card size="sm">
              <CardHeader>
                <CardTitle>الفئات العمرية</CardTitle>
                <CardDescription>محسوبة من تاريخ الميلاد حتى اليوم</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-2.5">
                {data.age_bands.map((b) => (
                  <BarRow key={b.code} label={ageBandLabels[b.code]} count={b.count} total={data.summary.current_people}
                    barClassName={b.code === "UNKNOWN" ? "bg-muted-foreground/40" : "bg-primary"} />
                ))}
              </CardContent>
            </Card>
            <Card size="sm">
              <CardHeader>
                <CardTitle>أماكن النزوح</CardTitle>
                <CardDescription>كما هي مسجّلة حرفيًا، دون توحيد للكتابة</CardDescription>
              </CardHeader>
              <CardContent>
                {data.top_locations.length === 0 ? (
                  <EmptyNote>لا توجد أماكن نزوح مسجّلة ضمن النطاق.</EmptyNote>
                ) : (
                  <ul className="divide-y rounded-lg border text-sm">
                    {data.top_locations.map((l) => (
                      <li key={l.location} className="flex items-center justify-between gap-3 px-3 py-1.5">
                        <span className="truncate">{l.location}</span>
                        <span className="shrink-0 tabular-nums text-muted-foreground">{fmt(l.families)} أسرة</span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          {data.organization && (
            <Card size="sm">
              <CardHeader>
                <CardTitle>التوزيع التنظيمي</CardTitle>
                <CardDescription>
                  {data.organization.level === "CLAN" ? "مجموعات الفروع — انقر لعرض الفروع" : "فروع المجموعة المختارة"}
                </CardDescription>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>مجموعة الفروع / الفرع</TableHead>
                      <TableHead>الأسر</TableHead>
                      <TableHead>الأفراد</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.organization.groups.map((g) => (
                      <GroupRows key={g.code} group={g} defaultOpen={data.organization?.level === "BRANCH_GROUP"} />
                    ))}
                    {data.organization.unassigned && (
                      <TableRow data-group="UNASSIGNED" className="border-t-2">
                        <TableCell className="font-medium">
                          غير محدد <span className="text-xs text-muted-foreground">(أسر بلا فرع)</span>
                        </TableCell>
                        <TableCell className="tabular-nums">{fmt(data.organization.unassigned.families)}</TableCell>
                        <TableCell className="tabular-nums">{fmt(data.organization.unassigned.people)}</TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          )}
        </div>
      )}
    </ReportState>
  );
}
