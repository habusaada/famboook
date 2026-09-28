"use client";

import { useState } from "react";
import {
  AlertCircle,
  ClipboardCheck,
  Download,
  HandHeart,
  HeartHandshake,
  HeartPulse,
  ListChecks,
  Lock,
  Users,
  type LucideIcon,
} from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { fmt } from "@/components/dashboard/dashboard-sections";
import { ApiError } from "@/lib/api/client";
import { downloadReport, type ReportParams } from "@/lib/api/reports";
import type { Paged, ReportKey } from "@/lib/types/api/reports";
import { cn } from "@/lib/utils";

/** The six existing reports: identity for the selector and header (presentation only). */
export const REPORT_DEFS: { key: ReportKey; label: string; description: string; icon: LucideIcon }[] = [
  { key: "population", label: "السكان والأسر", description: "الأسر النشطة والأفراد الحاليون والفئات العمرية والنزوح والتوزيع التنظيمي.", icon: Users },
  { key: "health", label: "الصحة", description: "أعداد إجمالية للحالات الصحية النشطة، دون أي تفاصيل أو قوائم أفراد.", icon: HeartPulse },
  { key: "needs", label: "الاحتياجات", description: "الاحتياجات حسب الحالة والأولوية والفئة ونوع المستهدف.", icon: HeartHandshake },
  { key: "assessments", label: "التقييمات", description: "الحالة الحالية لكل مجال حسب آخر تقييم مكتمل للأسرة.", icon: ClipboardCheck },
  { key: "assistance", label: "المساعدات", description: "برامج المساعدة: الاعتماد والتسليم الداخلي والكشوف الخارجية.", icon: HandHeart },
  { key: "data-quality", label: "جودة البيانات", description: "سجلات تحتاج إلى استكمال أو مراجعة، مشتقة من البيانات الحالية.", icon: ListChecks },
];

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
      <div className="flex flex-col gap-4" aria-busy="true" data-report-loading>
        <Skeleton className="h-20 w-full rounded-widget" />
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Skeleton className="h-48 w-full rounded-widget" />
          <Skeleton className="h-48 w-full rounded-widget" />
        </div>
      </div>
    );
  }
  return <>{children}</>;
}

export function ForbiddenReport() {
  return (
    <AppCard padded={false}>
      <EmptyState icon={Lock} title="لا تملك صلاحية عرض هذا التقرير." description="يظهر التقرير لمن يملك صلاحية المجال الخاص به." />
    </AppCard>
  );
}

/** Quiet in-card note for an empty breakdown ("no data in scope"), not an error. */
export function EmptyNote({ children }: { children: React.ReactNode }) {
  return <p className="py-2 text-sm text-muted-foreground">{children}</p>;
}

/**
 * Compact summary strip: the report's own figures (server values only),
 * separated by hairlines — never a KPI card grid.
 */
export function StatStrip({
  label,
  items,
}: {
  label: string;
  items: { label: string; value: number | null; hint?: string }[];
}) {
  return (
    <AppCard padded={false} className="overflow-hidden" aria-label={label} data-stat-strip>
      <dl
        className={cn(
          "grid grid-cols-2",
          items.length >= 5 ? "md:grid-cols-5" : items.length === 3 ? "md:grid-cols-3" : "md:grid-cols-4",
          "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t max-md:[&>*:last-child:nth-child(odd)]:col-span-2"
        )}
      >
        {items.map((item) => (
          <div key={item.label} className="flex min-w-0 flex-col gap-0.5 px-4 py-3 sm:px-5" data-stat={item.label}>
            <dt className="text-xs text-muted-foreground">{item.label}</dt>
            <dd className="text-xl leading-tight font-bold text-foreground tabular-nums">
              {item.value === null ? <span className="text-base font-normal text-muted-foreground">—</span> : <bdi>{fmt(item.value)}</bdi>}
            </dd>
            {item.hint && <span className="text-xs text-muted-foreground">{item.hint}</span>}
          </div>
        ))}
      </dl>
    </AppCard>
  );
}

/** A titled result section (breakdown or table). */
export function ReportSection({
  title,
  description,
  action,
  flush,
  className,
  children,
  ...rest
}: {
  title: React.ReactNode;
  description?: React.ReactNode;
  action?: React.ReactNode;
  flush?: boolean;
  className?: string;
  children: React.ReactNode;
} & Omit<React.ComponentProps<"section">, "title">) {
  return (
    <AppCard padded={false} className={cn("overflow-hidden", className)} {...rest}>
      <div className="px-4 pt-4 pb-3 sm:px-5">
        <SectionHeader title={title} description={description} action={action} />
      </div>
      <div className={flush ? "border-t border-stroke-subtle" : "px-4 pb-4 sm:px-5"}>{children}</div>
    </AppCard>
  );
}

/**
 * Report-specific filters + active-filter context + export, in one slim
 * toolbar. With no filters it collapses to the context line and export.
 */
export function ReportToolbar({
  filters,
  context,
  onReset,
  exportAction,
}: {
  filters?: React.ReactNode;
  context?: React.ReactNode;
  onReset?: () => void;
  exportAction?: React.ReactNode;
}) {
  if (!filters) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-2" data-report-toolbar>
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">{context}</p>
        {exportAction}
      </div>
    );
  }
  return (
    <AppCard padded={false} className="flex flex-col gap-3 p-3 sm:p-4" role="group" aria-label="مرشحات التقرير" data-report-toolbar>
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <div className="grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap sm:items-end">{filters}</div>
        <div className="flex flex-wrap items-center gap-2 sm:ms-auto">
          {onReset && (
            <Button variant="ghost" size="sm" onClick={onReset} className="h-9 text-muted-foreground" data-report-reset>
              إعادة الضبط
            </Button>
          )}
          {exportAction}
        </div>
      </div>
      {context && (
        <p className="border-t border-stroke-subtle pt-2.5 text-xs text-muted-foreground" data-report-context>
          {context}
        </p>
      )}
    </AppCard>
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
    <div
      className="flex flex-wrap items-center justify-between gap-2 border-t border-stroke-subtle px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5"
      data-pagination
    >
      <span>
        <bdi className="font-semibold text-foreground tabular-nums">{fmt(meta.total)}</bdi> {label}
      </span>
      <div className="flex items-center gap-1.5">
        <Button variant="outline" size="sm" className="h-8" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          السابق
        </Button>
        <span data-page={meta.current_page} className="min-w-24 px-2 text-center">
          صفحة <bdi className="font-semibold text-foreground tabular-nums">{fmt(meta.current_page)}</bdi> من{" "}
          <bdi className="tabular-nums">{fmt(Math.max(meta.last_page, 1))}</bdi>
        </span>
        <Button variant="outline" size="sm" className="h-8" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          التالي
        </Button>
      </div>
    </div>
  );
}

/** XLSX export of the current report view (same scope and filters). Hidden without export permission. */
export function ExportButton({
  report,
  params,
  canExport,
  label = "تصدير",
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
        className="h-9"
        disabled={pending}
        data-export={report}
        aria-label={`${label} بصيغة XLSX`}
        onClick={async () => {
          setPending(true);
          setError(null);
          const status = await downloadReport(report, params).catch(() => 0);
          setPending(false);
          if (status !== null) setError(status === 403 ? "لا تملك صلاحية التصدير." : "تعذّر إنشاء الملف.");
        }}
      >
        <Download className="size-4" />
        {pending ? (
          "جارٍ الإنشاء…"
        ) : (
          <>
            {label} <bdi dir="ltr">XLSX</bdi>
          </>
        )}
      </Button>
      {error && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
