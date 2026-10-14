"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { AlertCircle, ChevronLeft, ChevronRight, FileText, Plus, RotateCw, SearchX, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { StatStrip } from "@/components/shared/stat-strip";
import { FamilyRequestStatus, FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import {
  type CoordinatorRequestSummary,
  type CoordinatorRequestType,
  canStartCoordinatorRequest,
  coordinatorRequestErrorMessage,
  useCoordinatorRequestCount,
  useCoordinatorRequestTypesQuery,
  useCoordinatorRequestsQuery,
} from "@/lib/api/coordinator-requests";
import type { ChangeRequestStatus } from "@/lib/types/api/change-request";
import { coordinatorRequestTypeLabel, coordinatorRequestTypeLabels } from "@/lib/utils/coordinator-request";
import { formatTimestampDate } from "@/lib/utils/date";
import { FAMILY_REQUEST_FILTER_STATUSES, familyRequestStatusLabels } from "@/lib/utils/family-change-request";

const ALL = "ALL";
const BASE = "/family/coordinator/requests";
const TYPES = Object.keys(coordinatorRequestTypeLabels) as CoordinatorRequestType[];

/** Where the request is: the Family code, or a proposed Family's placement. */
export function whereLabel(request: Pick<CoordinatorRequestSummary, "family_code" | "placement">): string {
  if (request.family_code) return request.family_code;
  if (request.placement) return [request.placement.clan.name ?? request.placement.clan.code, request.placement.branch?.name ?? request.placement.branch?.code].filter(Boolean).join(" · ");
  return "—";
}

function href(request: CoordinatorRequestSummary) {
  return `${BASE}/${request.id}`;
}

function Overview() {
  const total = useCoordinatorRequestCount();
  const returned = useCoordinatorRequestCount("RETURNED_FOR_CLARIFICATION");
  const applied = useCoordinatorRequestCount("APPLIED");
  const rejected = useCoordinatorRequestCount("REJECTED");
  const value = (q: { data?: number; isError: boolean }) => (q.isError ? "—" : q.data?.toLocaleString("ar"));

  return (
    <section aria-label="ملخص الطلبات" data-coordinator-requests-overview>
      <StatStrip
        size="md"
        items={[
          { label: "كل طلباتي", value: value(total), href: BASE },
          { label: "بانتظار استكمالك", value: value(returned), href: `${BASE}?status=RETURNED_FOR_CLARIFICATION` },
          { label: "طُبّقت", value: value(applied), href: `${BASE}?status=APPLIED` },
          { label: "لم تُعتمد", value: value(rejected), href: `${BASE}?status=REJECTED` },
        ]}
      />
    </section>
  );
}

function RequestCard({ request }: { request: CoordinatorRequestSummary }) {
  return (
    <li>
      <Link
        href={href(request)}
        className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        aria-label={`عرض الطلب ${request.request_code}`}
        data-coordinator-request={request.id}
      >
        <span className="flex min-w-0 flex-1 flex-col gap-1">
          <span className="flex flex-wrap items-start justify-between gap-2">
            <span className="min-w-0 font-semibold text-foreground">{coordinatorRequestTypeLabel(request.type)}</span>
            <FamilyRequestStatus status={request.status} />
          </span>
          {request.summary && (
            <span className="text-sm break-words text-foreground">
              <bdi>{request.summary}</bdi>
            </span>
          )}
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <bdi dir="ltr" className="font-mono tracking-wide">
              {request.request_code}
            </bdi>
            <span aria-hidden>·</span>
            <bdi>{whereLabel(request)}</bdi>
            {request.submitted_at && (
              <>
                <span aria-hidden>·</span>
                <span>قُدّم {formatTimestampDate(request.submitted_at)}</span>
              </>
            )}
          </span>
        </span>
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

function RequestTable({ rows }: { rows: CoordinatorRequestSummary[] }) {
  const router = useRouter();
  return (
    <div className="hidden overflow-hidden rounded-2xl border border-border bg-surface-1 md:block">
      <Table data-coordinator-requests-table>
        <TableHeader>
          <TableRow>
            <TableHead>رمز الطلب</TableHead>
            <TableHead>النوع</TableHead>
            <TableHead>الاسم</TableHead>
            <TableHead>الأسرة أو المكان</TableHead>
            <TableHead>الحالة</TableHead>
            <TableHead>تاريخ التقديم</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.id} className="cursor-pointer" onClick={() => router.push(href(r))}>
              <TableCell>
                <Link href={href(r)} className="font-mono text-[13px] font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring" dir="ltr">
                  {r.request_code}
                </Link>
              </TableCell>
              <TableCell>{coordinatorRequestTypeLabel(r.type)}</TableCell>
              <TableCell className="max-w-56 truncate">
                <bdi>{r.summary ?? "—"}</bdi>
              </TableCell>
              <TableCell>
                <bdi>{whereLabel(r)}</bdi>
              </TableCell>
              <TableCell>
                <FamilyRequestStatus status={r.status} />
              </TableCell>
              <TableCell className="tabular-nums">{r.submitted_at ? formatTimestampDate(r.submitted_at) : "—"}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/**
 * «طلبات التنسيق» (CO-02.6b): the Coordinator's OWN requests still in their
 * current scope — GET /family/coordinator/requests. Counts come from the
 * server's totals (never one page of rows). Filters and the page live in the
 * URL; pagination is the server's. «طلب جديد» is offered only when the
 * server lists a type this client has a form for.
 */
export function CoordinatorRequests() {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const rawStatus = params.get("status") ?? "";
  const rawType = params.get("type") ?? "";
  const status = FAMILY_REQUEST_FILTER_STATUSES.includes(rawStatus as ChangeRequestStatus) ? (rawStatus as ChangeRequestStatus) : undefined;
  const type = TYPES.includes(rawType as CoordinatorRequestType) ? (rawType as CoordinatorRequestType) : undefined;
  const page = Math.max(1, Number(params.get("page")) || 1);
  const query = useCoordinatorRequestsQuery({ status, type, page });
  const types = useCoordinatorRequestTypesQuery();
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
  const canStart = canStartCoordinatorRequest(types.data);

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <FamilyRequestsHeader back={{ href: "/family/coordinator", label: "مساحة التنسيق" }} title="طلبات التنسيق" description="الطلبات التي قدّمتها ضمن نطاقك، وحالة مراجعتها." />
        {canStart && (
          <Button asChild className="h-11 gap-2 rounded-xl" data-coordinator-new-request>
            <Link href={`${BASE}/new`}>
              <Plus className="size-4" aria-hidden />
              طلب جديد
            </Link>
          </Button>
        )}
      </div>

      <Overview />

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
            {TYPES.map((t) => (
              <SelectItem key={t} value={t}>
                {coordinatorRequestTypeLabel(t)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {filtered && (
          <Button variant="ghost" className="h-11 rounded-xl text-muted-foreground" onClick={reset} data-coordinator-requests-reset>
            <X className="size-4" aria-hidden />
            إعادة الضبط
          </Button>
        )}
      </div>

      <div aria-busy={query.isFetching} aria-live="polite">
        {query.data ? (
          rows.length === 0 ? (
            <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-requests-empty>
              {filtered ? <SearchX className="size-6 text-muted-foreground" aria-hidden /> : <FileText className="size-6 text-muted-foreground" aria-hidden />}
              <p className="text-sm font-medium text-foreground">{filtered ? "لا توجد طلبات مطابقة" : "لم تقدّم طلبات بعد"}</p>
              <p className="text-[13px] text-muted-foreground">
                {filtered ? "غيّر التصفية أو أعدها لعرض كل الطلبات." : "تظهر هنا الطلبات التي تقدّمها لأسر نطاقك وحالة مراجعتها."}
              </p>
              {filtered && (
                <Button variant="outline" className="h-10 rounded-xl" onClick={reset}>
                  إعادة الضبط
                </Button>
              )}
            </section>
          ) : (
            <>
              <RequestTable rows={rows} />
              <ul className="flex flex-col gap-3 md:hidden" aria-label="طلبات التنسيق" data-coordinator-requests>
                {rows.map((r) => (
                  <RequestCard key={r.id} request={r} />
                ))}
              </ul>
            </>
          )
        ) : query.isError ? (
          <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-requests-error>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm font-medium text-foreground" role="alert">
              {coordinatorRequestErrorMessage(query.error)}
            </p>
            <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
              <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
              إعادة المحاولة
            </Button>
          </section>
        ) : (
          <div className="flex flex-col gap-3" data-coordinator-requests-loading>
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
        <nav aria-label="التنقل بين الصفحات" className="flex items-center justify-between gap-2" data-coordinator-requests-pagination>
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
