"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ChevronLeft, ClipboardPen, Loader2, Lock, Search, SearchX, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Code, PageHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { ChangeRequestStatusTag } from "@/components/change-requests/change-request-status";
import { ApiError } from "@/lib/api/client";
import { useChangeRequestQueue } from "@/lib/api/change-requests";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import type {
  ChangeRequestFilters,
  ChangeRequestStatus,
  ChangeRequestSummary,
  ChangeRequestType,
} from "@/lib/types/api/change-request";
import {
  CHANGE_REQUEST_FILTER_STATUSES,
  changeRequestStatusLabels,
  changeRequestTypeLabels,
  changeRequestsNoun,
} from "@/lib/utils/change-request";
import { cn } from "@/lib/utils";

const ALL = "ALL";
const fmt = (n: number) => n.toLocaleString("ar");
const head = "h-10 text-xs font-medium text-muted-foreground";
const REQUEST_CODE = /^CRQ-\d{6,12}$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

const day = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("ar", { day: "numeric", month: "short", year: "numeric" }) : "—";

/**
 * The search box takes a request code (CRQ-…) or a family code: a full
 * request code becomes the request_code filter, anything else the family
 * filter. Nothing unsupported is ever sent.
 */
export function searchFilter(term: string): Pick<ChangeRequestFilters, "family" | "request_code"> {
  const value = term.trim();
  if (!value) return {};
  const upper = value.toUpperCase();
  if (upper.startsWith("CRQ")) return REQUEST_CODE.test(upper) ? { request_code: upper } : {};
  return { family: value };
}

/** The latest milestone the API exposes for a row. */
function lastAction(r: ChangeRequestSummary): { label: string; at: string } | null {
  const candidates: [string, string | null][] = [
    ["طُبّق", r.applied_at],
    ["رُفض", r.rejected_at],
    ["أُلغي", r.cancelled_at],
    ["اعتُمد", r.approved_at],
    ["بدأت المراجعة", r.reviewed_at],
  ];
  const known = candidates.filter((c): c is [string, string] => c[1] !== null);
  if (known.length === 0) return null;
  const [label, at] = known.reduce((a, b) => (b[1] > a[1] ? b : a));
  return { label, at };
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-4 w-40" />
          <Skeleton className="ms-auto hidden h-5 w-20 sm:block" />
        </div>
      ))}
    </div>
  );
}

/** The Staff Change Request queue (PWA-5d): server-side filters and pagination, state in the URL. */
export function ChangeRequestsQueue() {
  const router = useRouter();
  const registry = useRegistrySearch();
  const status = registry.param("status");
  const type = registry.param("type");
  const from = registry.param("from");
  const to = registry.param("to");

  const search = searchFilter(registry.q);
  const filters: ChangeRequestFilters = {
    ...search,
    ...(CHANGE_REQUEST_FILTER_STATUSES.includes(status as ChangeRequestStatus) ? { status: status as ChangeRequestStatus } : {}),
    ...(type in changeRequestTypeLabels ? { type: type as ChangeRequestType } : {}),
    ...(DATE.test(from) ? { submitted_from: from } : {}),
    ...(DATE.test(to) && (!DATE.test(from) || to >= from) ? { submitted_to: to } : {}),
    page: registry.page,
  };
  const incompleteCode = registry.q.trim().toUpperCase().startsWith("CRQ") && !search.request_code;
  const invertedDates = DATE.test(from) && DATE.test(to) && to < from;

  const { data, isLoading, isFetching, isError, error, refetch } = useChangeRequestQueue(filters);
  const rows = data?.data ?? [];
  const hasFilters = Boolean(registry.text.trim() || status || type || from || to);
  const open = (id: string) => router.push(`/change-requests/${id}`);

  const header = (
    <PageHeader title="طلبات تحديث البيانات" description="مراجعة طلبات الأسر ومتابعة إجراءات اعتمادها وتطبيقها." />
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض طلبات تحديث البيانات." />
        </AppCard>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {header}

      <AppCard padded={false} className="overflow-hidden">
        {/* Toolbar: the PWA-5c server-side filters only. */}
        <div className="flex flex-col gap-2.5 p-3 sm:p-4" role="search" aria-label="تصفية طلبات تحديث البيانات">
          <div className="flex flex-col gap-2.5 lg:flex-row lg:items-center">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute start-3.5 top-1/2 size-[18px] -translate-y-1/2 text-subtle-foreground" aria-hidden />
              <Input
                type="search"
                value={registry.text}
                onChange={(event) => registry.setText(event.target.value)}
                placeholder="رقم الطلب (CRQ-…) أو رمز الأسرة"
                aria-label="بحث برقم الطلب أو رمز الأسرة"
                className="h-11 bg-surface-2 ps-10 text-[15px] [&::-webkit-search-cancel-button]:hidden"
                data-queue-search
              />
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
              <Select value={status || ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
                <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-48" aria-label="الحالة" data-filter-status>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>كل الحالات</SelectItem>
                  {CHANGE_REQUEST_FILTER_STATUSES.map((s) => (
                    <SelectItem key={s} value={s}>
                      {changeRequestStatusLabels[s]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={type || ALL} onValueChange={(value) => registry.setFilter("type", value === ALL ? "" : value)}>
                <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-52" aria-label="نوع الطلب" data-filter-type>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>كل الأنواع</SelectItem>
                  {(Object.keys(changeRequestTypeLabels) as ChangeRequestType[]).map((t) => (
                    <SelectItem key={t} value={t}>
                      {changeRequestTypeLabels[t]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex flex-col gap-1">
              <Label htmlFor="queue-from" className="text-xs text-muted-foreground">
                قُدّم من
              </Label>
              <Input id="queue-from" type="date" dir="ltr" value={from} max={to || undefined} onChange={(e) => registry.setFilter("from", e.target.value)} className="h-10 bg-surface-2 sm:w-44" />
            </div>
            <div className="flex flex-col gap-1">
              <Label htmlFor="queue-to" className="text-xs text-muted-foreground">
                إلى
              </Label>
              <Input id="queue-to" type="date" dir="ltr" value={to} min={from || undefined} onChange={(e) => registry.setFilter("to", e.target.value)} className="h-10 bg-surface-2 sm:w-44" />
            </div>
            {hasFilters && (
              <Button variant="ghost" onClick={registry.reset} className="h-10 text-muted-foreground sm:ms-auto" data-queue-reset>
                <X className="size-4" />
                إعادة الضبط
              </Button>
            )}
          </div>
          {incompleteCode && <p className="text-xs text-muted-foreground">أدخل رقم الطلب كاملًا، مثل CRQ-000001.</p>}
          {invertedDates && <p className="text-xs text-destructive">تاريخ النهاية قبل تاريخ البداية؛ لم يُطبَّق.</p>}
        </div>

        {/* Results context: meta.total of the current filters (no global counts exist). */}
        <div className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5" aria-live="polite">
          {data ? (
            <span className="text-muted-foreground" data-queue-total>
              <bdi className="font-semibold text-foreground tabular-nums">{fmt(data.meta.total)}</bdi> {changeRequestsNoun(data.meta.total)}
              {hasFilters ? " مطابقة" : ""}
            </span>
          ) : (
            <Skeleton className="h-4 w-20" />
          )}
          {isFetching && !isLoading && (
            <span className="ms-auto flex items-center gap-1.5 text-subtle-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              جارٍ التحديث
            </span>
          )}
        </div>

        <div className={cn("border-t border-stroke-subtle", isFetching && !isLoading && "opacity-60 transition-opacity")} aria-busy={isFetching}>
          {isLoading ? (
            <SkeletonRows />
          ) : isError ? (
            <div className="p-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل الطلبات</AlertTitle>
                <AlertDescription className="flex flex-col gap-2">
                  <span>{error instanceof ApiError && error.status === 422 ? "تحقق من عوامل التصفية." : "حدث خطأ أثناء الاتصال بالخادم."}</span>
                  <Button variant="outline" size="sm" className="w-fit" onClick={() => refetch()}>
                    إعادة المحاولة
                  </Button>
                </AlertDescription>
              </Alert>
            </div>
          ) : rows.length === 0 ? (
            hasFilters ? (
              <EmptyState
                icon={SearchX}
                title="لا توجد طلبات مطابقة"
                description="غيّر عوامل التصفية أو أعدها إلى الوضع الافتراضي."
                action={
                  <Button variant="outline" onClick={registry.reset}>
                    <X className="size-4" />
                    إعادة الضبط
                  </Button>
                }
              />
            ) : (
              <EmptyState icon={ClipboardPen} title="لا توجد طلبات تحديث بيانات" description="تظهر هنا طلبات الأسر بعد تقديمها من بوابة الأسرة." />
            )
          ) : (
            <>
              {/* Desktop (≥ lg): semantic table */}
              <Table className="hidden lg:table">
                <TableHeader className="bg-surface-1">
                  <TableRow className="border-stroke-subtle hover:bg-transparent">
                    <TableHead className={`${head} ps-5`}>الطلب</TableHead>
                    <TableHead className={head}>الأسرة</TableHead>
                    <TableHead className={head}>مقدّم الطلب</TableHead>
                    <TableHead className={head}>تاريخ التقديم</TableHead>
                    <TableHead className={head}>الحالة</TableHead>
                    <TableHead className={head}>آخر إجراء</TableHead>
                    <TableHead className={`${head} pe-5`}>
                      <span className="sr-only">فتح</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((r) => {
                    const last = lastAction(r);
                    return (
                      <TableRow
                        key={r.id}
                        data-request-id={r.id}
                        className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                        onClick={() => open(r.id)}
                      >
                        <TableCell className="ps-5">
                          <div className="flex min-w-0 flex-col">
                            <Link
                              href={`/change-requests/${r.id}`}
                              onClick={(e) => e.stopPropagation()}
                              className="w-fit rounded-sm font-semibold text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            >
                              <Code>{r.request_code}</Code>
                            </Link>
                            <span className="truncate text-xs text-muted-foreground">{changeRequestTypeLabels[r.type] ?? "طلب تحديث"}</span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-52">
                          <div className="flex min-w-0 flex-col">
                            <Code className="text-brand-800">{r.family.family_code}</Code>
                            <span className="truncate text-xs text-muted-foreground">{r.family.household_head_name ?? "رب الأسرة غير محدد"}</span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-48 truncate">{r.submitted_by.name ?? "—"}</TableCell>
                        <TableCell className="text-muted-foreground">{day(r.submitted_at)}</TableCell>
                        <TableCell>
                          <ChangeRequestStatusTag status={r.status} />
                        </TableCell>
                        <TableCell className="text-xs text-muted-foreground">{last ? `${last.label} · ${day(last.at)}` : "—"}</TableCell>
                        <TableCell className="pe-5 text-end">
                          <Button asChild variant="ghost" size="sm" className="h-8 gap-1 font-medium text-foreground group-hover:bg-surface-1 group-hover:text-brand-700">
                            <Link href={`/change-requests/${r.id}`} onClick={(e) => e.stopPropagation()} aria-label={`فتح الطلب ${r.request_code}`}>
                              فتح
                              <ChevronLeft className="size-4" />
                            </Link>
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>

              {/* Tablet and phone (< lg) */}
              <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="قائمة طلبات تحديث البيانات">
                {rows.map((r) => (
                  <li key={r.id} data-request-id={r.id}>
                    <Link
                      href={`/change-requests/${r.id}`}
                      className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                      aria-label={`فتح الطلب ${r.request_code} — ${r.family.family_code}`}
                    >
                      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div className="flex items-start justify-between gap-2">
                          <span className="min-w-0 font-semibold text-foreground">{changeRequestTypeLabels[r.type] ?? "طلب تحديث"}</span>
                          <ChangeRequestStatusTag status={r.status} />
                        </div>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <Code>{r.request_code}</Code>
                          <span aria-hidden>·</span>
                          <Code className="text-brand-800">{r.family.family_code}</Code>
                          <span aria-hidden>·</span>
                          <span>{day(r.submitted_at)}</span>
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

        {data && rows.length > 0 && (
          <div className="border-t border-stroke-subtle">
            <RegistryPagination meta={data.meta} onPage={registry.setPage} unit={changeRequestsNoun(data.meta.total)} />
          </div>
        )}
      </AppCard>
    </div>
  );
}
