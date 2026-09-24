"use client";

import { AlertCircle, HeartPulse, Lock } from "lucide-react";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
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
import { useFamilyHealth } from "@/lib/api/health";
import { ApiError } from "@/lib/api/client";
import type { FamilyDetail } from "@/lib/types/api/family";
import type { FamilyHealthSummary } from "@/lib/types/api/health";
import { healthRecordSubject, healthRecordTypeLabels } from "@/lib/utils/health";

// Every number is derived by the API on read — nothing here is stored.
const SUMMARY_CARDS: { key: keyof FamilyHealthSummary; label: string; persons?: boolean }[] = [
  { key: "disability_persons", label: "ذوو الإعاقة", persons: true },
  { key: "chronic_disease_persons", label: "الأمراض المزمنة", persons: true },
  { key: "pregnant", label: "الحوامل" },
  { key: "breastfeeding", label: "المرضعات" },
  { key: "under_two", label: "أطفال دون سنتين" },
  { key: "recent_births", label: "مواليد آخر 12 شهرًا" },
];

function personsLabel(count: number): string {
  if (count === 1) return "فرد";
  if (count === 2) return "فردان";
  if (count >= 3 && count <= 10) return "أفراد";
  return "فردًا";
}

export function FamilyHealthTab({ family }: { family: FamilyDetail }) {
  const { data, isLoading, isError, error } = useFamilyHealth(family.family_code);

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {SUMMARY_CARDS.map((card) => (
          <Skeleton key={card.key} className="h-20" />
        ))}
      </div>
    );
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">
            لا تملك صلاحية عرض البيانات الصحية لهذه الأسرة.
          </p>
        </div>
      );
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

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {SUMMARY_CARDS.map((card) => (
          <Card key={card.key} size="sm" data-summary={card.key}>
            <CardHeader className="pb-1">
              <CardDescription>{card.label}</CardDescription>
              <CardTitle className="text-2xl font-semibold tabular-nums">
                {summary[card.key]}
                {card.persons && (
                  <span className="ms-1.5 text-sm font-normal text-muted-foreground">
                    {personsLabel(summary[card.key])}
                  </span>
                )}
              </CardTitle>
            </CardHeader>
          </Card>
        ))}
      </div>
      <p className="-mt-2 text-xs text-muted-foreground">
        المؤشرات محسوبة تلقائيًا من السجلات النشطة وتواريخ الميلاد بتاريخ{" "}
        <span dir="ltr">{reference_date}</span>.
      </p>

      <Card size="sm">
        <CardHeader>
          <CardTitle>السجلات الصحية</CardTitle>
          <CardDescription>
            الإعاقات والأمراض المزمنة وحالات الحمل والرضاعة لأفراد الأسرة
          </CardDescription>
          {abilities.create && (
            <CardAction>
              <AddHealthRecordDialog familyCode={family.family_code} members={family.members} />
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="overflow-x-auto p-0">
          {records.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 p-12 text-center">
              <HeartPulse className="size-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                لا توجد حالات صحية مسجّلة لأفراد هذه الأسرة.
              </p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>الشخص</TableHead>
                  <TableHead>نوع الحالة</TableHead>
                  <TableHead>الحالة / النوع</TableHead>
                  <TableHead>التفاصيل</TableHead>
                  <TableHead>الفترة</TableHead>
                  <TableHead>الوضع</TableHead>
                  {canAct && <TableHead className="w-0" />}
                </TableRow>
              </TableHeader>
              <TableBody>
                {records.map((record) => (
                  <TableRow key={record.id} data-record-id={record.id}>
                    <TableCell className="font-medium">{record.person.full_name}</TableCell>
                    <TableCell>{healthRecordTypeLabels[record.type]}</TableCell>
                    <TableCell>{healthRecordSubject(record) ?? "—"}</TableCell>
                    <TableCell className="max-w-64 whitespace-normal text-muted-foreground">
                      {record.details || "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground" dir="ltr">
                      {record.started_at || record.ended_at
                        ? `${record.started_at ?? "…"} → ${record.ended_at ?? "…"}`
                        : "—"}
                    </TableCell>
                    <TableCell>
                      <Badge variant={record.is_active ? "default" : "outline"}>
                        {record.is_active ? "نشطة" : "منتهية"}
                      </Badge>
                    </TableCell>
                    {canAct && (
                      <TableCell>
                        <div className="flex items-center justify-end gap-1">
                          {abilities.update && (
                            <EditHealthRecordDialog familyCode={family.family_code} record={record} />
                          )}
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
          )}
        </CardContent>
      </Card>
    </div>
  );
}
