"use client";

import { useState } from "react";
import { AlertCircle, Download, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { fmt } from "@/components/dashboard/dashboard-sections";
import { ApiError } from "@/lib/api/client";
import { downloadReport, type ReportParams } from "@/lib/api/reports";
import type { Paged, ReportKey } from "@/lib/types/api/reports";

export function reportErrorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 403) return "لا تملك صلاحية عرض هذا التقرير.";
  if (error instanceof ApiError && error.status === 422) return error.message422 ?? "المرشحات المحددة غير صالحة.";
  return "تعذّر تحميل التقرير. الرجاء المحاولة مرة أخرى.";
}

/** Loading / error wrapper for one report query. */
export function ReportState({
  isLoading,
  error,
  children,
}: {
  isLoading: boolean;
  error: unknown;
  children: React.ReactNode;
}) {
  if (error) {
    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل التقرير</AlertTitle>
        <AlertDescription>{reportErrorMessage(error)}</AlertDescription>
      </Alert>
    );
  }
  if (isLoading) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-20 w-full rounded-xl" />
          ))}
        </div>
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }
  return <>{children}</>;
}

export function ForbiddenReport() {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground">
      <Lock className="size-6" />
      لا تملك صلاحية عرض هذا التقرير.
    </div>
  );
}

export function ReportPagination({
  meta,
  onPage,
  label = "سجل",
}: {
  meta: Paged<unknown>["meta"];
  onPage: (page: number) => void;
  label?: string;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground" data-pagination>
      <span>
        {fmt(meta.total)} {label}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          السابق
        </Button>
        <span data-page={meta.current_page}>
          صفحة {fmt(meta.current_page)} من {fmt(Math.max(meta.last_page, 1))}
        </span>
        <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          التالي
        </Button>
      </div>
    </div>
  );
}

/** XLSX export of the current report view (same scope and filters). */
export function ExportButton({
  report,
  params,
  canExport,
  label = "تصدير Excel",
}: {
  report: ReportKey;
  params: ReportParams;
  canExport: boolean;
  label?: string;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!canExport) return null;

  return (
    <div className="flex flex-col items-end gap-1">
      <Button
        variant="outline"
        size="sm"
        disabled={pending}
        data-export={report}
        onClick={async () => {
          setPending(true);
          setError(null);
          const status = await downloadReport(report, params).catch(() => 0);
          setPending(false);
          if (status !== null) setError(status === 403 ? "لا تملك صلاحية التصدير." : "تعذّر إنشاء الملف.");
        }}
      >
        <Download className="size-4" />
        {pending ? "جارٍ الإنشاء…" : label}
      </Button>
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}
