"use client";

import Link from "next/link";
import { AlertCircle, HandHeart, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useFamilyAssistances } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type { FamilyAssistanceRow } from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { executionModeShort, nomineeStatusLabels, receiptModeLabels } from "@/lib/utils/assistance";
import { FAMILY_TARGET_LABEL } from "@/lib/utils/need";

/** INTERNAL: delivery state. EXTERNAL: issued lists only — never "delivered". */
function Execution({ row }: { row: FamilyAssistanceRow }) {
  if (row.status !== "APPROVED") return <span className="text-muted-foreground">—</span>;

  if (row.assistance.execution_mode === "EXTERNAL") {
    return row.lists.length ? (
      <span className="flex flex-col gap-0.5 text-xs">
        <span>تم إصداره في كشف للجهة</span>
        {row.lists.map((l) => (
          <span key={l.list_number} className="text-muted-foreground">
            <span dir="ltr">{l.list_number}</span> — {formatDateTime(l.issued_at)}
          </span>
        ))}
      </span>
    ) : (
      <span className="text-xs text-muted-foreground">لم يُدرج في كشف بعد</span>
    );
  }

  return row.delivery ? (
    <span className="flex flex-col gap-0.5 text-xs">
      <Badge variant="secondary" className="w-fit">تم التسليم</Badge>
      <span className="text-muted-foreground">
        {formatDateTime(row.delivery.delivered_at)} — {receiptModeLabels[row.delivery.receipt_mode]}
        {row.delivery.recipient_name ? `: ${row.delivery.recipient_name}` : ""}
      </span>
    </span>
  ) : (
    <Badge variant="outline">بانتظار التسليم</Badge>
  );
}

export function FamilyAssistanceTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error } = useFamilyAssistances(familyCode);

  if (isLoading) return <Skeleton className="h-40" />;

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض مساعدات هذه الأسرة.</p>
        </div>
      );
    }
    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل المساعدات</AlertTitle>
        <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
      </Alert>
    );
  }

  const rows = data?.data ?? [];

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>المساعدات</CardTitle>
        <CardDescription>
          ترشيحات الأسرة وأفرادها للمساعدات. في التنفيذ الخارجي يظهر إصدار الكشف فقط، ولا يعني ذلك التسليم.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        {rows.length === 0 ? (
          <div className="flex flex-col items-center gap-2 border-t p-12 text-center">
            <HandHeart className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">لم تُرشَّح هذه الأسرة لأي مساعدة بعد.</p>
          </div>
        ) : (
          <div className="overflow-x-auto border-t">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>المساعدة</TableHead>
                  <TableHead>التصنيف</TableHead>
                  <TableHead>الجهة المقدمة</TableHead>
                  <TableHead>التنفيذ</TableHead>
                  <TableHead>المستفيد</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>التسليم / الكشوف</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id} data-family-assistance={row.assistance.title}>
                    <TableCell className="font-medium">
                      <Link href={`/assistances/${row.assistance.id}`} className="hover:underline">
                        {row.assistance.title}
                      </Link>
                    </TableCell>
                    <TableCell>{row.assistance.category.name}</TableCell>
                    <TableCell>{row.assistance.provider_name}</TableCell>
                    <TableCell>{executionModeShort[row.assistance.execution_mode]}</TableCell>
                    <TableCell>{row.person?.full_name ?? FAMILY_TARGET_LABEL}</TableCell>
                    <TableCell>
                      <Badge variant={row.status === "APPROVED" ? "default" : "outline"}>{nomineeStatusLabels[row.status]}</Badge>
                    </TableCell>
                    <TableCell>
                      <Execution row={row} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
