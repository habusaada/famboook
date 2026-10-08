"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { AlertCircle, ChevronLeft, ChevronRight, FileText, Plus, RotateCw, SearchX, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { FamilyRequestStatus, FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { isAccessFailure } from "@/lib/api/family-household";
import { type FamilyChangeRequestSummary, useFamilyChangeRequestsQuery } from "@/lib/api/family-change-requests";
import type { ChangeRequestStatus, ChangeRequestType } from "@/lib/types/api/change-request";
import { changeRequestTypeLabels } from "@/lib/utils/change-request";
import { formatTimestampDate } from "@/lib/utils/date";
import { FAMILY_REQUEST_FILTER_STATUSES, familyRequestStatusLabels } from "@/lib/utils/family-change-request";

const ALL = "ALL";

/** The latest milestone the API exposes for a row. */
function milestone(r: FamilyChangeRequestSummary): string | null {
  if (r.applied_at) return `طُبّق ${formatTimestampDate(r.applied_at)}`;
  if (r.rejected_at) return `رُفض ${formatTimestampDate(r.rejected_at)}`;
  if (r.cancelled_at) return `أُلغي ${formatTimestampDate(r.cancelled_at)}`;
  if (r.approved_at) return `اعتُمد ${formatTimestampDate(r.approved_at)}`;
  return null;
}

function RequestCard({ request }: { request: FamilyChangeRequestSummary }) {
  const latest = milestone(request);
  return (
    <li>
      <Link
        href={`/family/requests/${request.id}`}
        className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        aria-label={`عرض الطلب ${request.request_code}`}
        data-family-request={request.id}
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
          <FileText className="size-5" />
        </span>
        <span className="flex min-w-0 flex-1 flex-col gap-1">
          <span className="flex flex-wrap items-start justify-between gap-2">
            <span className="min-w-0 font-semibold text-foreground">{changeRequestTypeLabels[request.type] ?? "طلب تحديث"}</span>
            <FamilyRequestStatus status={request.status} />
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <bdi dir="ltr" className="font-mono tracking-wide">
              {request.request_code}
            </bdi>
            {request.submitted_at && (
              <>
                <span aria-hidden>·</span>
                <span>قُدّم {formatTimestampDate(request.submitted_at)}</span>
              </>
            )}
            {latest && (
              <>
                <span aria-hidden>·</span>
                <span>{latest}</span>
              </>
            )}
          </span>
        </span>
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

/**
 * «طلباتي» (PWA-5f): the WHOLE Family's request history — every request of
 * the Family, whoever submitted it — from GET /api/v1/family/change-requests.
 * Filters (status, type) and the page live in the URL; pagination is the
 * server's. No count beyond what the API returns.
 */
export function FamilyRequests() {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const rawStatus = params.get("status") ?? "";
  const rawType = params.get("type") ?? "";
  const status = FAMILY_REQUEST_FILTER_STATUSES.includes(rawStatus as ChangeRequestStatus) ? (rawStatus as ChangeRequestStatus) : undefined;
  const type = rawType in changeRequestTypeLabels ? (rawType as ChangeRequestType) : undefined;
  const page = Math.max(1, Number(params.get("page")) || 1);
  const query = useFamilyChangeRequestsQuery({ status, type, page });
  const filtered = Boolean(status || type);

  function update(patch: Record<string, string>) {
    const next = new URLSearchParams(params.toString());
    for (const [key, value] of Object.entries(patch)) {
      if (value) next.set(key, value);
      else next.delete(key);
    }
    const qs = next.toString();
    router.push(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }
  const setFilter = (key: string, value: string) => update({ [key]: value === ALL ? "" : value, page: "" });
  const reset = () => router.push(pathname, { scroll: false });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader title="طلباتي" description="تابع طلبات تحديث بيانات أسرتك وحالة مراجعتها." />

      <div className="flex flex-col gap-2 sm:flex-row" role="group" aria-label="تصفية الطلبات">
        <Select value={status ?? ALL} onValueChange={(value) => setFilter("status", value)}>
          <SelectTrigger className="h-11! w-full rounded-xl bg-surface-1 sm:w-56" aria-label="الحالة">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>كل الحالات</SelectItem>
            {FAMILY_REQUEST_FILTER_STATUSES.map((s) => (
              <SelectItem key={s} value={s}>
                {familyRequestStatusLabels[s]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={type ?? ALL} onValueChange={(value) => setFilter("type", value)}>
          <SelectTrigger className="h-11! w-full rounded-xl bg-surface-1 sm:w-56" aria-label="نوع الطلب">
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
        {filtered && (
          <Button variant="ghost" className="h-11 rounded-xl text-muted-foreground" onClick={reset} data-family-requests-reset>
            <X className="size-4" aria-hidden />
            إعادة الضبط
          </Button>
        )}
      </div>

      <div aria-busy={query.isPending} aria-live="polite">
        {query.data ? (
          rows.length === 0 ? (
            <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-requests-empty>
              {filtered ? <SearchX className="size-6 text-muted-foreground" aria-hidden /> : <FileText className="size-6 text-muted-foreground" aria-hidden />}
              <p className="text-sm font-medium text-foreground">{filtered ? "لا توجد طلبات مطابقة" : "لا توجد طلبات لأسرتك بعد"}</p>
              <p className="text-[13px] text-muted-foreground">
                {filtered ? "غيّر التصفية أو أعدها لعرض كل الطلبات." : "تظهر هنا طلبات تحديث بيانات أسرتك ومتابعة مراجعتها."}
              </p>
              {filtered ? (
                <Button variant="outline" className="h-10 rounded-xl" onClick={reset}>
                  إعادة الضبط
                </Button>
              ) : (
                <Button asChild variant="outline" className="h-10 gap-2 rounded-xl">
                  <Link href="/family/requests/new">
                    <Plus className="size-4" aria-hidden />
                    طلب جديد
                  </Link>
                </Button>
              )}
            </section>
          ) : (
            <ul className="flex flex-col gap-3" aria-label="طلبات الأسرة" data-family-requests>
              {rows.map((r) => (
                <RequestCard key={r.id} request={r} />
              ))}
            </ul>
          )
        ) : query.isError ? (
          !isAccessFailure(query.error) && (
            <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-requests-error>
              <AlertCircle className="size-6 text-danger" aria-hidden />
              <p className="text-sm font-medium text-foreground" role="alert">
                تعذّر تحميل الطلبات
              </p>
              <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                إعادة المحاولة
              </Button>
            </section>
          )
        ) : (
          <div className="flex flex-col gap-3" data-family-requests-loading>
            <p role="status" className="sr-only">
              جارٍ تحميل الطلبات
            </p>
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-2xl" />
            ))}
          </div>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <nav aria-label="التنقل بين الصفحات" className="flex items-center justify-between gap-2" data-family-requests-pagination>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page <= 1} onClick={() => update({ page: meta.current_page - 1 > 1 ? String(meta.current_page - 1) : "" })}>
            <ChevronRight className="size-4" aria-hidden />
            السابق
          </Button>
          <span className="text-[13px] text-muted-foreground" aria-current="page">
            صفحة <span className="tabular-nums">{meta.current_page.toLocaleString("ar")}</span> من{" "}
            <span className="tabular-nums">{meta.last_page.toLocaleString("ar")}</span>
          </span>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page >= meta.last_page} onClick={() => update({ page: String(meta.current_page + 1) })}>
            التالي
            <ChevronLeft className="size-4" aria-hidden />
          </Button>
        </nav>
      )}
    </div>
  );
}
