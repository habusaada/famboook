"use client";

import { AlertCircle, HeartPulse, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  AddHealthRecordDialog,
  CloseHealthRecordDialog,
  EditHealthRecordDialog,
} from "@/components/families/health-record-dialogs";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { useFamilyHealth } from "@/lib/api/health";
import { ApiError } from "@/lib/api/client";
import type { FamilyDetail } from "@/lib/types/api/family";
import type { FamilyHealthSummary } from "@/lib/types/api/health";
import { healthRecordSubject, healthRecordTypeLabels } from "@/lib/utils/health";
import { cn } from "@/lib/utils";

// Every number is derived by the API on read — nothing here is stored.
const SUMMARY: { key: keyof FamilyHealthSummary; label: string; persons?: boolean }[] = [
  { key: "disability_persons", label: "ذوو الإعاقة", persons: true },
  { key: "chronic_disease_persons", label: "الأمراض المزمنة", persons: true },
  { key: "pregnant", label: "الحوامل" },
  { key: "breastfeeding", label: "المرضعات" },
  { key: "under_two", label: "أطفال دون سنتين" },
  { key: "recent_births", label: "مواليد آخر 12 شهرًا" },
];

const head = "h-10 text-xs font-medium text-muted-foreground";

function personsLabel(count: number): string {
  if (count === 1) return "فرد";
  if (count === 2) return "فردان";
  if (count >= 3 && count <= 10) return "أفراد";
  return "فردًا";
}

function Restricted({ message }: { message: string }) {
  return (
    <AppCard padded={false}>
      <EmptyState icon={Lock} title={message} />
    </AppCard>
  );
}

export function FamilyHealthTab({ family }: { family: FamilyDetail }) {
  const { data, isLoading, isError, error } = useFamilyHealth(family.family_code);

  if (isLoading) {
    return <Skeleton className="h-48 w-full rounded-widget" />;
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return <Restricted message="لا تملك صلاحية عرض البيانات الصحية لهذه الأسرة." />;
    }
    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل البيانات الصحية</AlertTitle>
        <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
      </Alert>
    );
  }

  const { data: records, summary, reference_date, abilities } = data!;
  const canAct = abilities.update || abilities.close;
  // Active records first; ended ones recede.
  const ordered = [...records].sort((a, b) => Number(b.is_active) - Number(a.is_active));

  return (
    <div className="flex flex-col gap-4">
      <AppCard padded={false} className="overflow-hidden">
        <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 [&>*]:border-stroke-subtle max-sm:[&>*:nth-child(odd)]:border-e max-sm:[&>*:nth-child(n+3)]:border-t sm:max-xl:[&>*:not(:nth-child(3n))]:border-e sm:max-xl:[&>*:nth-child(n+4)]:border-t xl:[&>*:not(:last-child)]:border-e">
          {SUMMARY.map((item) => (
            <div key={item.key} className="flex flex-col gap-0.5 px-4 py-3" data-summary={item.key}>
              <span className="text-xs text-muted-foreground">{item.label}</span>
              <span className={cn("text-xl font-bold tabular-nums", summary[item.key] > 0 ? "text-foreground" : "text-subtle-foreground")}>
                {summary[item.key].toLocaleString("ar")}
                {item.persons && (
                  <span className="ms-1 text-xs font-normal text-muted-foreground">{personsLabel(summary[item.key])}</span>
                )}
              </span>
            </div>
          ))}
        </div>
        <p className="border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-xs text-subtle-foreground">
          المؤشرات محسوبة تلقائيًا من السجلات النشطة وتواريخ الميلاد بتاريخ <bdi dir="ltr">{reference_date}</bdi>.
        </p>
      </AppCard>

      <AppCard padded={false} className="overflow-hidden">
        <SectionHeader
          className="p-4 sm:p-5"
          icon={HeartPulse}
          tone="info"
          title="السجلات الصحية"
          description="الإعاقات والأمراض المزمنة وحالات الحمل والرضاعة لأفراد الأسرة"
          action={abilities.create ? <AddHealthRecordDialog familyCode={family.family_code} members={family.members} /> : undefined}
        />
        {records.length === 0 ? (
          <div className="border-t border-stroke-subtle">
            <EmptyState icon={HeartPulse} title="لا توجد حالات صحية مسجّلة لأفراد هذه الأسرة" />
          </div>
        ) : (
          <div className="overflow-x-auto border-t border-stroke-subtle">
            <Table>
              <TableHeader className="bg-surface-2">
                <TableRow className="hover:bg-transparent">
                  <TableHead className={`${head} ps-5`}>الشخص</TableHead>
                  <TableHead className={head}>نوع الحالة</TableHead>
                  <TableHead className={head}>الحالة / النوع</TableHead>
                  <TableHead className={head}>التفاصيل</TableHead>
                  <TableHead className={head}>الفترة</TableHead>
                  <TableHead className={head}>الوضع</TableHead>
                  {canAct && <TableHead className={`${head} w-0 pe-5`} />}
                </TableRow>
              </TableHeader>
              <TableBody>
                {ordered.map((record) => (
                  <TableRow
                    key={record.id}
                    data-record-id={record.id}
                    className={cn("h-14 border-stroke-subtle hover:bg-surface-hover", !record.is_active && "text-muted-foreground")}
                  >
                    <TableCell className="ps-5 font-medium">{record.person.full_name}</TableCell>
                    <TableCell>{healthRecordTypeLabels[record.type]}</TableCell>
                    <TableCell>{healthRecordSubject(record) ?? "—"}</TableCell>
                    <TableCell className="max-w-64 whitespace-normal text-muted-foreground">{record.details || "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {record.started_at || record.ended_at ? (
                        <bdi dir="ltr">{`${record.started_at ?? "…"} → ${record.ended_at ?? "…"}`}</bdi>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell>
                      <StatusBadge tone={record.is_active ? "info" : "neutral"}>{record.is_active ? "نشطة" : "منتهية"}</StatusBadge>
                    </TableCell>
                    {canAct && (
                      <TableCell className="pe-4">
                        <div className="flex items-center justify-end gap-1">
                          {abilities.update && <EditHealthRecordDialog familyCode={family.family_code} record={record} />}
                          {abilities.close && record.is_active && (
                            <CloseHealthRecordDialog familyCode={family.family_code} record={record} />
                          )}
                        </div>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </AppCard>
    </div>
  );
}
