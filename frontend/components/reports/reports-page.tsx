"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Lock } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DashboardScopeFilters } from "@/components/dashboard/dashboard-scope-filters";
import { AssessmentsReport } from "@/components/reports/assessments-report";
import { AssistanceReport } from "@/components/reports/assistance-report";
import { DataQualityReport } from "@/components/reports/data-quality-report";
import { HealthReport } from "@/components/reports/health-report";
import { NeedsReport } from "@/components/reports/needs-report";
import { PopulationReport } from "@/components/reports/population-report";
import { ForbiddenReport, reportErrorMessage } from "@/components/reports/report-parts";
import { useReportMeta, useReportScopeOptions } from "@/lib/api/reports";
import type { ReportKey } from "@/lib/types/api/reports";

const REPORTS: { key: ReportKey; label: string }[] = [
  { key: "population", label: "السكان والأسر" },
  { key: "health", label: "الصحة" },
  { key: "needs", label: "الاحتياجات" },
  { key: "assessments", label: "التقييمات" },
  { key: "assistance", label: "المساعدات" },
  { key: "data-quality", label: "جودة البيانات" },
];

// URL keys: report, clan, group, branch + report filters (codes only, nothing sensitive).
const FILTER_KEYS = ["status", "priority", "category", "target", "domain", "rating", "issue", "page"] as const;

/**
 * Reports V1: one report at a time over the shared organizational scope.
 * All state lives in the URL (bookmarkable, back/forward safe).
 */
export function ReportsPage() {
  const router = useRouter();
  const pathname = usePathname();
  const search = useSearchParams();
  const meta = useReportMeta();
  const options = useReportScopeOptions();
  const clans = options.data?.data ?? [];

  const report = (REPORTS.find((r) => r.key === search.get("report"))?.key ?? "population") as ReportKey;
  // With exactly one active Clan it is preselected (never hard-coded).
  const clan = search.get("clan") ?? (clans.length === 1 ? clans[0].code : "");
  const scopeValue = { clan, group: search.get("group") ?? "", branch: search.get("branch") ?? "" };
  const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, search.get(k) ?? ""]).filter(([, v]) => v));
  const scope = { clan, branch_group: scopeValue.group || undefined, branch: scopeValue.branch || undefined };

  function navigate(next: Record<string, string>) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(next)) if (value) params.set(key, value);
    router.push(`${pathname}?${params.toString()}`, { scroll: false });
  }
  const current = () => ({ report, ...scopeValue, ...filters });
  // Changing the scope or report keeps filters meaningful: page resets.
  const setScope = (value: typeof scopeValue) => navigate({ ...current(), ...value, page: "" });
  const setReport = (key: string) => navigate({ report: key, ...scopeValue });
  const setFilters = (patch: Record<string, string>, resetPage = false) =>
    navigate({ ...current(), ...patch, ...(resetPage ? { page: "" } : {}) });

  const allowed = meta.data?.data.reports;
  const canExport = meta.data?.data.can_export ?? false;
  const props = { scope, filters, setFilters, canExport };

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h2 className="text-2xl font-semibold tracking-tight">التقارير</h2>
        <p className="text-sm text-muted-foreground">تقارير تشغيلية محسوبة مباشرة من السجل الحالي ضمن النطاق المحدد</p>
      </div>

      <Card size="sm">
        <CardContent>
          {options.isLoading ? (
            <Skeleton className="h-14 w-full" />
          ) : options.isError ? (
            <p className="text-sm text-destructive">{reportErrorMessage(options.error)}</p>
          ) : (
            <DashboardScopeFilters clans={clans} value={scopeValue} onChange={setScope} />
          )}
        </CardContent>
      </Card>

      <Tabs value={report} onValueChange={setReport} dir="rtl">
        <TabsList variant="line" className="w-full justify-start overflow-x-auto border-b">
          {REPORTS.map((r) => (
            <TabsTrigger key={r.key} value={r.key} data-report-tab={r.key}>
              {allowed && !allowed[r.key] && <Lock className="size-3.5" />}
              {r.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {!clan ? (
        !options.isLoading && (
          <div className="rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground">
            اختر العشيرة / العائلة لعرض التقرير.
          </div>
        )
      ) : !allowed ? (
        <Skeleton className="h-64 w-full rounded-xl" />
      ) : !allowed[report] ? (
        <ForbiddenReport />
      ) : report === "population" ? (
        <PopulationReport scope={scope} canExport={canExport} />
      ) : report === "health" ? (
        <HealthReport scope={scope} canExport={canExport} />
      ) : report === "needs" ? (
        <NeedsReport {...props} />
      ) : report === "assessments" ? (
        <AssessmentsReport {...props} />
      ) : report === "assistance" ? (
        <AssistanceReport {...props} />
      ) : (
        <DataQualityReport {...props} />
      )}
    </div>
  );
}

