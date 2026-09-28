"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient, type InfiniteData } from "@tanstack/react-query";
import { AlertCircle, ChevronLeft, HandHeart, Loader2, Lock, Plus, SearchX, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { PageHeader } from "@/components/shared/page-layout";
import { AssistanceFormDialog } from "@/components/assistances/assistance-form-dialog";
import {
  AssistanceStatusTag,
  ExecutionModeTag,
  matchingProgramsNoun,
  nomineesAgainstTarget,
  programsNoun,
} from "@/components/assistances/assistance-case";
import { useAssistances } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import { useAssistanceCategories } from "@/lib/api/reference";
import type {
  Assistance,
  AssistanceFilters,
  AssistanceListResponse,
  AssistanceStatus,
  AssistanceType,
} from "@/lib/types/api/assistance";
import {
  ASSISTANCE_STATUSES,
  ASSISTANCE_TYPES,
  assistanceStatusLabels,
  assistanceTypeLabels,
} from "@/lib/utils/assistance";
import { cn } from "@/lib/utils";

const ALL = "ALL";
const fmt = (n: number) => n.toLocaleString("ar");
const isFiltered = (f: AssistanceFilters) => Boolean(f.status || f.type || f.category);
const head = "h-10 text-xs font-medium text-muted-foreground";

function Filter({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value?: string;
  onChange: (value?: string) => void;
  options: { value: string; label: string }[];
}) {
  return (
    <Select value={value ?? ALL} onValueChange={(v) => onChange(v === ALL ? undefined : v)}>
      <SelectTrigger size="sm" aria-label={label} className="h-10! min-w-36 bg-surface-2 max-sm:w-full max-sm:flex-1">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL}>{label}: الكل</SelectItem>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * Registry total: `meta.total` of the unfiltered list — the current one, or
 * (while filtering) the unfiltered result already in the query cache. No
 * extra request; unknown ("—") when that result is not cached.
 */
function useRegistryTotal(current: InfiniteData<AssistanceListResponse> | undefined, filters: AssistanceFilters) {
  const queryClient = useQueryClient();
  if (!isFiltered(filters)) return current?.pages[0]?.meta.total;
  const cached = queryClient.getQueryData<InfiniteData<AssistanceListResponse>>(["assistances", "list", {}]);
  return cached?.pages[0]?.meta.total ?? null;
}

function RegistrySummary({ total }: { total: number | null | undefined }) {
  return (
    <AppCard padded={false} className="flex items-center gap-3 px-4 py-3 sm:px-5" data-registry-summary>
      <IconBox icon={HandHeart} size="sm" />
      <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="text-[13px] font-medium text-muted-foreground">برامج المساعدات</span>
        {total === undefined ? (
          <Skeleton className="h-6 w-10 self-center" />
        ) : total === null ? (
          <span className="text-xl font-bold text-muted-foreground" aria-label="غير متاح أثناء التصفية">
            —
          </span>
        ) : (
          <>
            <bdi className="text-xl font-bold tabular-nums text-foreground">{fmt(total)}</bdi>
            <span className="text-[13px] text-muted-foreground">{programsNoun(total)} في السجل</span>
          </>
        )}
      </div>
    </AppCard>
  );
}

/** Planned dates as recorded; never invented. */
function Period({ assistance }: { assistance: Assistance }) {
  const { start_date: start, end_date: end } = assistance;
  if (!start && !end) return <span className="text-muted-foreground">غير محددة</span>;
  return (
    <bdi dir="ltr" className="tabular-nums">
      {start ?? "…"} → {end ?? "…"}
    </bdi>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <div className="flex flex-col gap-1.5">
            <Skeleton className="h-4 w-56" />
            <Skeleton className="h-3 w-24" />
          </div>
          <Skeleton className="ms-auto hidden h-5 w-16 sm:block" />
          <Skeleton className="hidden h-5 w-16 sm:block" />
        </div>
      ))}
    </div>
  );
}

/** Global Assistance programs/campaigns page (/assistances). */
export function AssistancesList() {
  const router = useRouter();
  const [filters, setFilters] = useState<AssistanceFilters>({});
  const categories = useAssistanceCategories().data?.data ?? [];
  const { data, isLoading, isFetching, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useAssistances(filters);
  const registryTotal = useRegistryTotal(data, filters);

  const assistances = data?.pages.flatMap((p) => p.data) ?? [];
  const total = data?.pages[0]?.meta.total;
  const canCreate = data?.pages[0]?.abilities.create ?? false;
  const filtered = isFiltered(filters);
  const reset = () => setFilters({});
  const refreshing = isFetching && !isLoading && !isFetchingNextPage;

  const createButton = (
    <AssistanceFormDialog
      onSaved={(response) => router.push(`/assistances/${response.data.id}`)}
      trigger={
        <Button>
          <Plus className="size-4" />
          مساعدة جديدة
        </Button>
      }
    />
  );

  const header = (
    <PageHeader
      title="المساعدات"
      description="برامج وحملات المساعدة: التعريف والاستهداف والترشيح والاعتماد والتنفيذ. الترشيح لا يعني استلام المساعدة."
      actions={canCreate ? createButton : undefined}
    />
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض المساعدات." />
        </AppCard>
      </div>
    );
  }

  const active = [
    filters.status && assistanceStatusLabels[filters.status],
    filters.type && assistanceTypeLabels[filters.type],
    filters.category && (categories.find((c) => c.code === filters.category)?.name ?? filters.category),
  ].filter(Boolean) as string[];

  return (
    <div className="flex flex-col gap-4">
      {header}
      <RegistrySummary total={registryTotal} />

      <AppCard padded={false} className="overflow-hidden">
        {/* Toolbar: the existing server-side filters. */}
        <div className="flex flex-col gap-2.5 p-3 sm:flex-row sm:flex-wrap sm:items-center sm:p-4" role="group" aria-label="تصفية برامج المساعدات">
          <div className="flex flex-wrap items-center gap-2">
            <Filter
              label="الحالة"
              value={filters.status}
              onChange={(v) => setFilters({ ...filters, status: v as AssistanceStatus | undefined })}
              options={ASSISTANCE_STATUSES.map((s) => ({ value: s, label: assistanceStatusLabels[s] }))}
            />
            <Filter
              label="التصنيف"
              value={filters.category}
              onChange={(v) => setFilters({ ...filters, category: v })}
              options={categories.map((c) => ({ value: c.code, label: c.name }))}
            />
            <Filter
              label="النوع"
              value={filters.type}
              onChange={(v) => setFilters({ ...filters, type: v as AssistanceType | undefined })}
              options={ASSISTANCE_TYPES.map((t) => ({ value: t, label: assistanceTypeLabels[t] }))}
            />
          </div>
          {filtered && (
            <Button variant="ghost" onClick={reset} className="h-10 text-muted-foreground sm:ms-auto" data-assistances-reset>
              <X className="size-4" />
              إعادة الضبط
            </Button>
          )}
        </div>

        {/* Results context: meta.total of the current filters. */}
        <div
          className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5"
          aria-live="polite"
          aria-label="نتائج برامج المساعدات"
        >
          {total !== undefined ? (
            <span className="text-muted-foreground">
              <bdi className="font-semibold text-foreground tabular-nums">{fmt(total)}</bdi>{" "}
              {filtered ? matchingProgramsNoun(total) : programsNoun(total)}
            </span>
          ) : (
            <Skeleton className="h-4 w-24" />
          )}
          {active.length > 0 && (
            <span className="text-muted-foreground" data-active-filters>
              {active.join(" · ")}
            </span>
          )}
          {refreshing && (
            <span className="ms-auto flex items-center gap-1.5 text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              جارٍ التحديث
            </span>
          )}
        </div>

        <div className={cn("border-t border-stroke-subtle", refreshing && "opacity-60 transition-opacity")}>
          {isLoading ? (
            <SkeletonRows />
          ) : isError ? (
            <div className="p-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل المساعدات</AlertTitle>
                <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
              </Alert>
            </div>
          ) : assistances.length === 0 ? (
            filtered ? (
              <EmptyState
                icon={SearchX}
                title="لا توجد برامج مطابقة"
                description="لا توجد برامج مساعدة تطابق عوامل التصفية المحددة."
                action={
                  <Button variant="outline" onClick={reset}>
                    <X className="size-4" />
                    إعادة الضبط
                  </Button>
                }
              />
            ) : (
              <EmptyState
                icon={HandHeart}
                title="لا توجد برامج مساعدة بعد"
                description="تُنشأ برامج المساعدة ثم تُفتح لإضافة المرشحين واعتمادهم."
                action={canCreate ? createButton : undefined}
              />
            )
          ) : (
            <>
              {/* Desktop (≥ lg): semantic table */}
              <Table className="hidden lg:table">
                <TableHeader className="bg-surface-1">
                  <TableRow className="border-stroke-subtle hover:bg-transparent">
                    <TableHead className={`${head} ps-5`}>البرنامج</TableHead>
                    <TableHead className={head}>النمط / النوع</TableHead>
                    <TableHead className={head}>الجهة المقدمة</TableHead>
                    <TableHead className={head}>الحالة</TableHead>
                    <TableHead className={head}>المرشحون / الهدف</TableHead>
                    <TableHead className={head}>الفترة</TableHead>
                    <TableHead className={`${head} pe-5`}>
                      <span className="sr-only">فتح</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assistances.map((a) => (
                    <TableRow
                      key={a.id}
                      data-assistance-id={a.id}
                      data-assistance-status={a.status}
                      className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                      onClick={() => router.push(`/assistances/${a.id}`)}
                    >
                      <TableCell className="max-w-80 ps-5">
                        <div className="flex min-w-0 flex-col">
                          <Link
                            href={`/assistances/${a.id}`}
                            onClick={(e) => e.stopPropagation()}
                            className="truncate rounded-sm font-semibold text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                          >
                            {a.title}
                          </Link>
                          <span className="truncate text-xs text-muted-foreground">{a.category.name}</span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-col items-start gap-1">
                          <ExecutionModeTag mode={a.execution_mode} />
                          <span className="text-xs text-muted-foreground">{assistanceTypeLabels[a.assistance_type]}</span>
                        </div>
                      </TableCell>
                      <TableCell className="max-w-48">
                        {a.provider_name ? (
                          <span className="block truncate text-foreground">{a.provider_name}</span>
                        ) : (
                          <span className="text-muted-foreground">غير محددة</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <AssistanceStatusTag status={a.status} />
                      </TableCell>
                      <TableCell className="text-foreground" data-beneficiaries>
                        {nomineesAgainstTarget(a.nominee_count, a.target_beneficiaries)}
                      </TableCell>
                      <TableCell className="text-[13px]">
                        <Period assistance={a} />
                      </TableCell>
                      <TableCell className="pe-5 text-end">
                        <Button asChild variant="ghost" size="sm" className="h-8 gap-1 font-medium text-foreground group-hover:bg-surface-1 group-hover:text-brand-700">
                          <Link href={`/assistances/${a.id}`} onClick={(e) => e.stopPropagation()} aria-label={`عرض البرنامج: ${a.title}`}>
                            عرض
                            <ChevronLeft className="size-4" />
                          </Link>
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {/* Tablet and phone (< lg): compact operational rows */}
              <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="قائمة برامج المساعدات">
                {assistances.map((a) => (
                  <li key={a.id} data-assistance-id={a.id}>
                    <Link
                      href={`/assistances/${a.id}`}
                      className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                      aria-label={`عرض البرنامج: ${a.title}`}
                    >
                      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div className="flex items-start justify-between gap-2">
                          <span className="min-w-0 font-semibold text-foreground">{a.title}</span>
                          <AssistanceStatusTag status={a.status} />
                        </div>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <span>{a.category.name}</span>
                          <span aria-hidden>·</span>
                          <ExecutionModeTag mode={a.execution_mode} />
                          <span aria-hidden>·</span>
                          <span>{assistanceTypeLabels[a.assistance_type]}</span>
                        </span>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <span>{a.provider_name || "الجهة غير محددة"}</span>
                          <span aria-hidden>·</span>
                          <span className="text-foreground">{nomineesAgainstTarget(a.nominee_count, a.target_beneficiaries)}</span>
                          {(a.start_date || a.end_date) && (
                            <>
                              <span aria-hidden>·</span>
                              <Period assistance={a} />
                            </>
                          )}
                        </span>
                      </div>
                      <ChevronLeft className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    </Link>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>

        {/* Server pagination (unchanged "load more"). */}
        {data && assistances.length > 0 && total !== undefined && (
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-stroke-subtle px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5" data-assistances-pagination>
            <span>
              عرض <bdi className="font-semibold text-foreground tabular-nums">{fmt(assistances.length)}</bdi> من{" "}
              <bdi className="tabular-nums">{fmt(total)}</bdi> {programsNoun(total)}
            </span>
            {hasNextPage && (
              <Button type="button" variant="outline" size="sm" className="h-8" disabled={isFetchingNextPage} onClick={() => fetchNextPage()}>
                {isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
                عرض المزيد
              </Button>
            )}
          </div>
        )}
      </AppCard>
    </div>
  );
}
