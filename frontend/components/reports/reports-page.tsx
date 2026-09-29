"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { ChevronLeft, Lock, MapPin, Network } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { PageHeader } from "@/components/shared/page-layout";
import { DashboardScopeFilters, groupLabel } from "@/components/dashboard/dashboard-scope-filters";
import { AssessmentsReport } from "@/components/reports/assessments-report";
import { AssistanceReport } from "@/components/reports/assistance-report";
import { DataQualityReport } from "@/components/reports/data-quality-report";
import { HealthReport } from "@/components/reports/health-report";
import { NeedsReport } from "@/components/reports/needs-report";
import { PopulationReport } from "@/components/reports/population-report";
import { ForbiddenReport, REPORT_DEFS, reportErrorMessage } from "@/components/reports/report-parts";
import { useReportMeta, useReportScopeOptions } from "@/lib/api/reports";
import type { Clan } from "@/lib/types/api/clan";
import type { ReportKey } from "@/lib/types/api/reports";
import { cn } from "@/lib/utils";

// URL keys: report, clan, group, branch + report filters (codes only, nothing sensitive).
const FILTER_KEYS = ["status", "priority", "category", "target", "domain", "rating", "issue", "page"] as const;

/** Clan › Branch Group › Branch as selected (names from the loaded scope options). */
function scopePath(clans: Clan[], value: { clan: string; group: string; branch: string }): string[] {
  const clan = clans.find((c) => c.code === value.clan);
  if (!clan) return [];
  const parts = [clan.name];
  const group = clan.branch_groups?.find((g) => g.code === value.group);
  if (group) parts.push(groupLabel(group));
  const branch = [
    ...(group ? [group] : clan.branch_groups ?? []).flatMap((g) => g.branches ?? []),
    ...(group ? [] : clan.ungrouped_branches ?? []),
  ].find((b) => b.code === value.branch);
  if (branch) parts.push(branch.name);
  return parts;
}

/**
 * Reports V2: select a report → define the organizational scope and the
 * report's own filters → review results → export. All state lives in the
 * URL (bookmarkable, back/forward safe); calculations are the server's.
 */
export function ReportsPage() {
  const router = useRouter();
  const pathname = usePathname();
  const search = useSearchParams();
  const meta = useReportMeta();
  const options = useReportScopeOptions();
  const clans = options.data?.data ?? [];

  const report = (REPORT_DEFS.find((r) => r.key === search.get("report"))?.key ?? "population") as ReportKey;
  const def = REPORT_DEFS.find((r) => r.key === report)!;
  // With exactly one active Clan it is preselected (never hard-coded).
  const clan = search.get("clan") ?? (clans.length === 1 ? clans[0].code : "");
  const scopeValue = { clan, group: search.get("group") ?? "", branch: search.get("branch") ?? "" };
  const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, search.get(k) ?? ""]).filter(([, v]) => v));
  const scope = { clan, branch_group: scopeValue.group || undefined, branch: scopeValue.branch || undefined };

  function href(next: Record<string, string>) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(next)) if (value) params.set(key, value);
    return `${pathname}?${params.toString()}`;
  }
  const navigate = (next: Record<string, string>) => router.push(href(next), { scroll: false });
  const current = () => ({ report, ...scopeValue, ...filters });
  // Changing the scope or report keeps filters meaningful: page resets.
  const setScope = (value: typeof scopeValue) => navigate({ ...current(), ...value, page: "" });
  const setFilters = (patch: Record<string, string>, resetPage = false) =>
    navigate({ ...current(), ...patch, ...(resetPage ? { page: "" } : {}) });

  const allowed = meta.data?.data.reports;
  const canExport = meta.data?.data.can_export ?? false;
  const props = { scope, filters, setFilters, canExport };
  const path = scopePath(clans, scopeValue);

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="التقارير" description="تقارير تشغيلية وإحصائية محسوبة مباشرة من البيانات المسجلة، ضمن النطاق التنظيمي المحدد." />

      <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
        {/* A. Report selection */}
        <nav aria-label="اختيار التقرير" className="lg:sticky lg:top-4">
          <ul className="flex gap-1.5 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0" data-report-nav>
            {REPORT_DEFS.map((r) => {
              const active = r.key === report;
              const locked = allowed ? !allowed[r.key] : false;
              return (
                <li key={r.key} className="shrink-0 lg:shrink">
                  <Link
                    href={href({ report: r.key, ...scopeValue })}
                    scroll={false}
                    aria-current={active ? "page" : undefined}
                    data-report-tab={r.key}
                    className={cn(
                      "flex items-center gap-2.5 rounded-lg border px-3 py-2 transition-colors focus-visible:outline-2 focus-visible:outline-ring lg:py-2.5",
                      active
                        ? "border-brand-700/25 bg-surface-selected text-brand-800 shadow-e1"
                        : "border-transparent text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    )}
                  >
                    <r.icon className={cn("size-4 shrink-0", active ? "text-brand-700" : "text-muted-foreground")} aria-hidden />
                    <span className="flex min-w-0 flex-col">
                      <span className={cn("text-sm whitespace-nowrap", active && "font-semibold")}>{r.label}</span>
                      <span className="hidden text-xs leading-snug text-muted-foreground lg:line-clamp-2">{r.description}</span>
                    </span>
                    {locked && (
                      <Lock className="ms-auto size-3.5 shrink-0 text-muted-foreground" aria-label="غير متاح لصلاحياتك" />
                    )}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="flex min-w-0 flex-col gap-4">
          {/* Report identity + B. organizational scope */}
          <AppCard padded={false} className="overflow-hidden" aria-label="التقرير والنطاق" data-report-identity={report}>
            <div className="flex items-start gap-3.5 p-4 sm:p-5">
              <IconBox icon={def.icon} size="md" />
              <div className="flex min-w-0 flex-col gap-0.5">
                <h2 className="text-lg leading-tight font-bold text-foreground">{def.label}</h2>
                <p className="text-[13px] text-muted-foreground">{def.description}</p>
              </div>
            </div>
            <div className="flex flex-col gap-3 border-t border-stroke-subtle bg-surface-2/60 px-4 py-3 sm:px-5">
              <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <Network className="size-3.5 text-muted-foreground" aria-hidden />
                النطاق التنظيمي
              </div>
              {options.isLoading ? (
                <Skeleton className="h-10 w-full" />
              ) : options.isError ? (
                <p className="text-sm text-destructive">{reportErrorMessage(options.error)}</p>
              ) : (
                <DashboardScopeFilters clans={clans} value={scopeValue} onChange={setScope} />
              )}
              {path.length > 0 && (
                <p className="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground" data-scope-summary>
                  <MapPin className="size-3.5" aria-hidden />
                  <span>النطاق الحالي:</span>
                  {path.map((part, i) => (
                    <span key={`${i}-${part}`} className="flex items-center gap-1">
                      {i > 0 && <ChevronLeft className="size-3" aria-hidden />}
                      <span className={i === path.length - 1 ? "font-semibold text-foreground" : undefined}>{part}</span>
                    </span>
                  ))}
                  {path.length === 1 && <span>(العشيرة كاملة)</span>}
                </p>
              )}
            </div>
          </AppCard>

          {/* C. Filters, results and export */}
          {!clan ? (
            !options.isLoading && (
              <AppCard padded={false}>
                <EmptyState icon={Network} title="اختر العشيرة / العائلة لعرض التقرير" description="تُحسب التقارير دائمًا ضمن نطاق تنظيمي محدد." />
              </AppCard>
            )
          ) : !allowed ? (
            <Skeleton className="h-64 w-full rounded-widget" />
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
      </div>
    </div>
  );
}
