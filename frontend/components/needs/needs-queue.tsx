"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient, type InfiniteData } from "@tanstack/react-query";
import { AlertCircle, ChevronLeft, HeartHandshake, Loader2, Lock, SearchX, UserRound, Users, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { Code, PageHeader } from "@/components/shared/page-layout";
import { NeedFilterBar } from "@/components/needs/need-filters";
import { NeedPriorityTag, NeedStatusTag, needAgeLabel, needsNoun } from "@/components/needs/need-case";
import { ApiError } from "@/lib/api/client";
import { useNeedCategories } from "@/lib/api/reference";
import { useNeedsQueue } from "@/lib/api/needs";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { Need, NeedFilters } from "@/lib/types/api/need";
import { FAMILY_TARGET_LABEL, needPriorityLabels, needStatusLabels } from "@/lib/utils/need";
import { cn } from "@/lib/utils";

const fmt = (n: number) => n.toLocaleString("ar");

// The queue opens on OPEN needs (unchanged default).
const DEFAULT_FILTERS: NeedFilters = { status: "OPEN" };
const isDefault = (f: NeedFilters) =>
  f.status === "OPEN" && !f.priority && !f.category && !f.target && !f.family;

const head = "h-10 text-xs font-medium text-muted-foreground";

/**
 * Open work across all families: `meta.total` of the default (OPEN) query —
 * the current one, or the result already in the query cache. No extra
 * request; unknown ("—") when that result is no longer cached.
 */
function useOpenTotal(current: InfiniteData<PaginatedResponse<Need>> | undefined, filters: NeedFilters) {
  const queryClient = useQueryClient();
  if (isDefault(filters)) return current?.pages[0]?.meta.total;
  const cached = queryClient.getQueryData<InfiniteData<PaginatedResponse<Need>>>(["needs", "queue", DEFAULT_FILTERS]);
  return cached?.pages[0]?.meta.total ?? null;
}

function OpenSummary({ total }: { total: number | null | undefined }) {
  return (
    <AppCard padded={false} className="flex items-center gap-3 px-4 py-3 sm:px-5" data-needs-summary>
      <IconBox icon={HeartHandshake} size="sm" />
      <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="text-[13px] font-medium text-muted-foreground">الاحتياجات المفتوحة</span>
        {total === undefined ? (
          <Skeleton className="h-6 w-10 self-center" />
        ) : total === null ? (
          <span className="text-xl font-bold text-muted-foreground" aria-label="غير متاح">
            —
          </span>
        ) : (
          <>
            <bdi className="text-xl font-bold tabular-nums text-foreground">{fmt(total)}</bdi>
            <span className="text-[13px] text-muted-foreground">{needsNoun(total)} قيد المتابعة في كل الأسر</span>
          </>
        )}
      </div>
    </AppCard>
  );
}

/** The dates that matter for this state: age while open, resolution once resolved. */
function NeedDates({ need }: { need: Need }) {
  const created = <bdi dir="ltr" className="tabular-nums">{need.created_at.slice(0, 10)}</bdi>;
  if (need.status === "OPEN") {
    return (
      <div className="flex flex-col">
        <span className="text-foreground">{needAgeLabel(need.created_at)}</span>
        <span className="text-xs text-muted-foreground">{created}</span>
      </div>
    );
  }
  return (
    <div className="flex flex-col">
      <span className="text-muted-foreground">
        {need.status === "FULFILLED" ? "لُبّي " : "أُغلق "}
        {need.resolved_at ? <bdi dir="ltr" className="tabular-nums">{need.resolved_at.slice(0, 10)}</bdi> : "—"}
      </span>
      <span className="text-xs text-muted-foreground">أُنشئ {created}</span>
    </div>
  );
}

function Target({ need, linked = true }: { need: Need; linked?: boolean }) {
  if (!need.person) {
    return (
      <span className="flex items-center gap-1.5 text-muted-foreground">
        <Users className="size-3.5 shrink-0" aria-hidden />
        {FAMILY_TARGET_LABEL} كاملة
      </span>
    );
  }
  return (
    <span className="flex min-w-0 items-center gap-1.5">
      <UserRound className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      {linked ? (
        <Link
          href={`/people/${encodeURIComponent(need.person.person_code)}`}
          onClick={(e) => e.stopPropagation()}
          className="truncate rounded-sm text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        >
          {need.person.full_name}
        </Link>
      ) : (
        <span className="truncate text-foreground">{need.person.full_name}</span>
      )}
    </span>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <div className="flex flex-col gap-1.5">
            <Skeleton className="h-4 w-52" />
            <Skeleton className="h-3 w-24" />
          </div>
          <Skeleton className="ms-auto hidden h-5 w-16 sm:block" />
          <Skeleton className="hidden h-5 w-16 sm:block" />
        </div>
      ))}
    </div>
  );
}

/** Cross-family operational work queue; OPEN needs by default. */
export function NeedsQueue() {
  const router = useRouter();
  const [filters, setFilters] = useState<NeedFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isFetching, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useNeedsQueue(filters);
  const openTotal = useOpenTotal(data, filters);
  const categories = useNeedCategories().data?.data ?? [];

  const header = (
    <PageHeader title="الاحتياجات" description="قائمة العمل لكل الأسر: المفتوحة والعاجلة أولًا. التلبية والإغلاق من صفحة الاحتياج." />
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض الاحتياجات." />
        </AppCard>
      </div>
    );
  }

  const needs = data?.pages.flatMap((page) => page.data) ?? [];
  const total = data?.pages[0]?.meta.total;
  const filtered = !isDefault(filters);
  const reset = () => setFilters(DEFAULT_FILTERS);
  const active = [
    filters.status ? needStatusLabels[filters.status] : "كل الحالات",
    filters.priority && needPriorityLabels[filters.priority],
    filters.category && (categories.find((c) => c.code === filters.category)?.name ?? filters.category),
    filters.target && (filters.target === "family" ? "على مستوى الأسرة" : "فرد من الأسرة"),
  ].filter(Boolean) as string[];

  return (
    <div className="flex flex-col gap-4">
      {header}
      <OpenSummary total={openTotal} />

      <AppCard padded={false} className="overflow-hidden">
        {/* Toolbar: the existing server-side filters. */}
        <div className="flex flex-col gap-2.5 p-3 sm:flex-row sm:flex-wrap sm:items-center sm:p-4" role="group" aria-label="تصفية الاحتياجات">
          <NeedFilterBar filters={filters} onChange={setFilters} showTarget triggerClassName="h-10! bg-surface-2 max-sm:w-full max-sm:flex-1" />
          {filtered && (
            <Button variant="ghost" onClick={reset} className="h-10 text-muted-foreground sm:ms-auto" data-needs-reset>
              <X className="size-4" />
              إعادة الضبط
            </Button>
          )}
        </div>

        {/* Results context: meta.total of the current filters. */}
        <div className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5" aria-live="polite">
          {total !== undefined ? (
            <span className="text-muted-foreground">
              <bdi className="font-semibold text-foreground tabular-nums">{fmt(total)}</bdi> {needsNoun(total)}
            </span>
          ) : (
            <Skeleton className="h-4 w-24" />
          )}
          <span className="text-muted-foreground" data-active-filters>
            {active.join(" · ")}
          </span>
          {isFetching && !isLoading && !isFetchingNextPage && (
            <span className="ms-auto flex items-center gap-1.5 text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              جارٍ التحديث
            </span>
          )}
        </div>

        <div className={cn("border-t border-stroke-subtle", isFetching && !isLoading && !isFetchingNextPage && "opacity-60 transition-opacity")}>
          {isLoading ? (
            <SkeletonRows />
          ) : isError ? (
            <div className="p-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل الاحتياجات</AlertTitle>
                <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
              </Alert>
            </div>
          ) : needs.length === 0 ? (
            filtered ? (
              <EmptyState
                icon={SearchX}
                title="لا توجد احتياجات مطابقة"
                description="غيّر عوامل التصفية أو أعدها إلى الاحتياجات المفتوحة."
                action={
                  <Button variant="outline" onClick={reset}>
                    <X className="size-4" />
                    إعادة الضبط
                  </Button>
                }
              />
            ) : (
              <EmptyState
                icon={HeartHandshake}
                title="لا توجد احتياجات مفتوحة"
                description="تُسجَّل الاحتياجات من ملف الأسرة، وتظهر هنا حتى تُلبّى أو تُغلق."
              />
            )
          ) : (
            <>
              {/* Desktop (≥ lg): semantic table */}
              <Table className="hidden lg:table">
                <TableHeader className="bg-surface-1">
                  <TableRow className="border-stroke-subtle hover:bg-transparent">
                    <TableHead className={`${head} ps-5`}>الاحتياج</TableHead>
                    <TableHead className={head}>الأسرة</TableHead>
                    <TableHead className={head}>المستفيد</TableHead>
                    <TableHead className={head}>الأولوية</TableHead>
                    <TableHead className={head}>الحالة</TableHead>
                    <TableHead className={head}>التاريخ</TableHead>
                    <TableHead className={`${head} pe-5`}>
                      <span className="sr-only">فتح</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {needs.map((need) => {
                    const open = need.status === "OPEN";
                    return (
                      <TableRow
                        key={need.id}
                        data-need-id={need.id}
                        data-need-status={need.status}
                        className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                        onClick={() => router.push(`/needs/${need.id}`)}
                      >
                        <TableCell className="max-w-80 ps-5">
                          <div className="flex min-w-0 flex-col">
                            <Link
                              href={`/needs/${need.id}`}
                              onClick={(e) => e.stopPropagation()}
                              className={cn(
                                "truncate rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring",
                                open ? "font-semibold text-foreground" : "font-medium text-foreground/85"
                              )}
                            >
                              {need.title}
                            </Link>
                            <span className="truncate text-xs text-muted-foreground">{need.category.name}</span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-52">
                          <div className="flex min-w-0 flex-col">
                            <Link
                              href={`/families/${encodeURIComponent(need.family.family_code)}?tab=needs`}
                              onClick={(e) => e.stopPropagation()}
                              className="w-fit rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                              data-family-link
                            >
                              <Code>{need.family.family_code}</Code>
                            </Link>
                            <span className="truncate text-xs text-muted-foreground">
                              {need.family.household_head_name ?? "رب الأسرة غير محدد"}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-48">
                          <Target need={need} />
                        </TableCell>
                        <TableCell>
                          <NeedPriorityTag priority={need.priority} />
                        </TableCell>
                        <TableCell>
                          <NeedStatusTag status={need.status} />
                        </TableCell>
                        <TableCell>
                          <NeedDates need={need} />
                        </TableCell>
                        <TableCell className="pe-5 text-end">
                          <Button asChild variant="ghost" size="sm" className="h-8 gap-1 font-medium text-foreground group-hover:bg-surface-1 group-hover:text-brand-700">
                            <Link href={`/needs/${need.id}`} onClick={(e) => e.stopPropagation()} aria-label={`عرض الاحتياج: ${need.title}`}>
                              عرض
                              <ChevronLeft className="size-4" />
                            </Link>
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>

              {/* Tablet and phone (< lg): compact work items */}
              <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="قائمة الاحتياجات">
                {needs.map((need) => {
                  const open = need.status === "OPEN";
                  return (
                    <li key={need.id} data-need-id={need.id}>
                      <Link
                        href={`/needs/${need.id}`}
                        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                        aria-label={`عرض الاحتياج: ${need.title} — ${need.family.family_code}`}
                      >
                        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                          <div className="flex items-start justify-between gap-2">
                            <span className={cn("min-w-0", open ? "font-semibold text-foreground" : "font-medium text-foreground/85")}>
                              {need.title}
                            </span>
                            <NeedPriorityTag priority={need.priority} />
                          </div>
                          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span>{need.category.name}</span>
                            <span aria-hidden>·</span>
                            <Code className="text-brand-800">{need.family.family_code}</Code>
                            <span aria-hidden>·</span>
                            <Target need={need} linked={false} />
                          </span>
                          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <NeedStatusTag status={need.status} />
                            {open ? (
                              <span>{needAgeLabel(need.created_at)}</span>
                            ) : (
                              need.resolved_at && (
                                <span>
                                  {need.status === "FULFILLED" ? "لُبّي " : "أُغلق "}
                                  <bdi dir="ltr" className="tabular-nums">{need.resolved_at.slice(0, 10)}</bdi>
                                </span>
                              )
                            )}
                          </span>
                        </div>
                        <ChevronLeft className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </div>

        {/* Server pagination (unchanged "load more"). */}
        {data && needs.length > 0 && total !== undefined && (
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-stroke-subtle px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5" data-needs-pagination>
            <span>
              عرض <bdi className="font-semibold text-foreground tabular-nums">{fmt(needs.length)}</bdi> من{" "}
              <bdi className="tabular-nums">{fmt(total)}</bdi> {needsNoun(total)}
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
