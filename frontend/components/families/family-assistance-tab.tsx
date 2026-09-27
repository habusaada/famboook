"use client";

import Link from "next/link";
import { AlertCircle, HandHeart, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
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

// Nomination status tone: approved is the active path; removed/rejected recede.
const statusTone = (status: string): StatusTone =>
  status === "APPROVED" ? "success" : status === "NOMINATED" ? "info" : status === "NOT_DELIVERED" ? "warning" : "neutral";

/** INTERNAL: delivery state. EXTERNAL: issued lists only — never "delivered". */
function Execution({ row }: { row: FamilyAssistanceRow }) {
  if (row.status !== "APPROVED") return <span className="text-muted-foreground">—</span>;

  if (row.assistance.execution_mode === "EXTERNAL") {
    return row.lists.length ? (
      <span className="flex flex-col gap-0.5 text-xs">
        <span>تم إصداره في كشف للجهة</span>
        {row.lists.map((l) => (
          <span key={l.list_number} className="text-muted-foreground">
            <bdi dir="ltr">{l.list_number}</bdi> — {formatDateTime(l.issued_at)}
          </span>
        ))}
      </span>
    ) : (
      <span className="text-xs text-muted-foreground">لم يُدرج في كشف بعد</span>
    );
  }

  return row.delivery ? (
    <span className="flex flex-col gap-0.5 text-xs">
      <StatusBadge tone="success" className="w-fit">تم التسليم</StatusBadge>
      <span className="text-muted-foreground">
        {formatDateTime(row.delivery.delivered_at)} — {receiptModeLabels[row.delivery.receipt_mode]}
        {row.delivery.recipient_name ? `: ${row.delivery.recipient_name}` : ""}
      </span>
    </span>
  ) : (
    <StatusBadge tone="neutral">بانتظار التسليم</StatusBadge>
  );
}

export function FamilyAssistanceTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error } = useFamilyAssistances(familyCode);

  if (isLoading) return <Skeleton className="h-40 w-full rounded-widget" />;

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض مساعدات هذه الأسرة." />
        </AppCard>
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
  const head = "h-10 text-xs font-medium text-muted-foreground";

  return (
    <AppCard padded={false} className="overflow-hidden">
      <SectionHeader
        className="p-4 sm:p-5"
        icon={HandHeart}
        tone="success"
        title="المساعدات"
        description="ترشيحات الأسرة وأفرادها للمساعدات. في التنفيذ الخارجي يظهر إصدار الكشف فقط، ولا يعني ذلك التسليم."
      />
      {rows.length === 0 ? (
        <div className="border-t border-stroke-subtle">
          <EmptyState icon={HandHeart} title="لم تُرشَّح هذه الأسرة لأي مساعدة بعد" />
        </div>
      ) : (
        <div className="overflow-x-auto border-t border-stroke-subtle">
          <Table>
            <TableHeader className="bg-surface-2">
              <TableRow className="hover:bg-transparent">
                <TableHead className={`${head} ps-5`}>المساعدة</TableHead>
                <TableHead className={head}>التصنيف</TableHead>
                <TableHead className={head}>الجهة المقدمة</TableHead>
                <TableHead className={head}>التنفيذ</TableHead>
                <TableHead className={head}>المستفيد</TableHead>
                <TableHead className={head}>الحالة</TableHead>
                <TableHead className={`${head} pe-5`}>التسليم / الكشوف</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow
                  key={row.id}
                  data-family-assistance={row.assistance.title}
                  className="h-14 border-stroke-subtle hover:bg-surface-hover"
                >
                  <TableCell className="ps-5 font-medium">
                    <Link href={`/assistances/${row.assistance.id}`} className="rounded-sm hover:text-brand-700 hover:underline focus-visible:outline-2 focus-visible:outline-ring">
                      {row.assistance.title}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{row.assistance.category.name}</TableCell>
                  <TableCell className="text-muted-foreground">{row.assistance.provider_name}</TableCell>
                  <TableCell>{executionModeShort[row.assistance.execution_mode]}</TableCell>
                  <TableCell>{row.person?.full_name ?? FAMILY_TARGET_LABEL}</TableCell>
                  <TableCell>
                    <StatusBadge tone={statusTone(row.status)}>{nomineeStatusLabels[row.status]}</StatusBadge>
                  </TableCell>
                  <TableCell className="pe-5">
                    <Execution row={row} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </AppCard>
  );
}
