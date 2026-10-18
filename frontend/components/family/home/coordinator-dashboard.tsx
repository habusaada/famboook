"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, ClipboardList, FilePlus2, Network, RotateCw, Search, UserPlus, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { scopeLabel, useCoordinatorContextQuery, useCoordinatorSummaryQuery } from "@/lib/api/coordinator";
import { coordinatorRequestFormFor, useCoordinatorRequestTypesQuery } from "@/lib/api/coordinator-requests";

// FB-FAMILY-DASHBOARD-01: the Coordinator section of the Family home, below
// the family's own content. Rendered only when /family/me says Coordinator
// Space is open; every figure comes from the server (context and summary —
// never a list length), and every endpoint authorizes on its own. A failure
// here stays here: the family content above is unaffected.

const card = "rounded-2xl border border-brand-100 bg-surface-1 p-4";

function Stat({ label, value, field }: { label: string; value: number; field: string }) {
  return (
    <div className="flex min-h-20 flex-col justify-between gap-1.5 rounded-xl border border-stroke-subtle bg-surface-2 p-3" data-coordinator-stat={field}>
      <dt className="text-xs leading-snug text-muted-foreground">{label}</dt>
      <dd className="text-xl leading-none font-bold text-foreground tabular-nums">{value}</dd>
    </div>
  );
}

function Action({ href, label, icon: Icon, field }: { href: string | null; label: string; icon: LucideIcon; field: string }) {
  const body = (
    <>
      <Icon className={`size-4 shrink-0 ${href ? "text-brand-700" : "text-subtle-foreground"}`} aria-hidden />
      <span className={`min-w-0 flex-1 text-sm ${href ? "font-semibold text-foreground" : "font-medium text-muted-foreground"}`}>{label}</span>
      {href ? (
        <ChevronLeft className="size-4 shrink-0 text-muted-foreground" aria-hidden />
      ) : (
        <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium text-subtle-foreground">غير متاح حاليًا</span>
      )}
    </>
  );
  return (
    <li>
      {href ? (
        <Link
          href={href}
          className="flex min-h-12 items-center gap-2.5 rounded-xl border border-border px-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
          data-coordinator-action={field}
        >
          {body}
        </Link>
      ) : (
        <div className="flex min-h-12 items-center gap-2.5 rounded-xl border border-border px-3 opacity-70" aria-disabled="true" data-coordinator-action-unavailable={field}>
          {body}
        </div>
      )}
    </li>
  );
}

export function CoordinatorDashboard() {
  const context = useCoordinatorContextQuery();
  const summary = useCoordinatorSummaryQuery();
  const types = useCoordinatorRequestTypesQuery();

  // A request type is offered only when the server lists it and this client has its form.
  const formFor = (type: "REGISTER_FAMILY" | "ADD_FAMILY_MEMBER") =>
    types.data?.meta.submission_enabled && types.data.data.some((t) => t.type === type) ? coordinatorRequestFormFor(type) : null;

  const failed = context.isError || summary.isError;
  const loading = !failed && (!context.data || !summary.data);

  return (
    <section className={card} aria-labelledby="coordinator-dashboard-title" aria-busy={loading} data-coordinator-dashboard>
      <div className="flex items-center gap-2.5">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white" aria-hidden>
          <Network className="size-[18px]" />
        </span>
        <div className="min-w-0">
          <h2 id="coordinator-dashboard-title" className="text-base font-semibold text-foreground">
            مساحة التنسيق
          </h2>
          <p className="text-xs text-muted-foreground">نطاق التنسيق المعيّن لك ومتابعة طلباتك</p>
        </div>
      </div>

      {failed ? (
        <div className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-border px-3 py-3" data-coordinator-dashboard-error>
          <p className="flex items-center gap-2 text-sm text-foreground" role="alert">
            <AlertCircle className="size-4 shrink-0 text-danger" aria-hidden />
            تعذّر تحميل بيانات التنسيق
          </p>
          <Button
            variant="outline"
            className="h-10 gap-2 px-3"
            onClick={() => {
              void context.refetch();
              void summary.refetch();
            }}
            disabled={context.isFetching || summary.isFetching}
          >
            <RotateCw className="size-4" aria-hidden />
            إعادة المحاولة
          </Button>
        </div>
      ) : loading ? (
        <div className="mt-4 flex flex-col gap-3" data-coordinator-dashboard-loading>
          <p role="status" className="sr-only">
            جارٍ تحميل بيانات التنسيق
          </p>
          <Skeleton className="h-7 w-40 rounded-lg" />
          <div className="grid grid-cols-2 gap-2.5">
            <Skeleton className="h-20 rounded-xl" />
            <Skeleton className="h-20 rounded-xl" />
            <Skeleton className="h-20 rounded-xl" />
            <Skeleton className="h-20 rounded-xl" />
          </div>
        </div>
      ) : (
        <>
          <h3 className="mt-4 text-xs font-medium text-subtle-foreground">نطاق التنسيق</h3>
          <ul className="mt-1.5 flex flex-wrap gap-1.5" data-coordinator-dashboard-scopes>
            {context.data!.scopes.map((scope) => (
              <li
                key={`${scope.type}-${scope.code}`}
                className="rounded-lg border border-brand-100 bg-brand-50 px-2.5 py-1 text-[13px] font-medium text-brand-900"
              >
                <bdi>{scopeLabel(scope)}</bdi>
              </li>
            ))}
          </ul>

          <div className="@container mt-4">
            <dl className="grid grid-cols-2 gap-2.5 @[25rem]:grid-cols-4">
              <Stat label="الأسر في النطاق" value={summary.data!.family_count} field="families" />
              <Stat label="الأفراد المسجّلون" value={summary.data!.person_count} field="persons" />
              <Stat label="طلبات قيد المعالجة" value={summary.data!.requests.in_progress} field="in-progress" />
              <Stat label="طلبات مكتملة" value={summary.data!.requests.completed} field="completed" />
            </dl>
          </div>
          {summary.data!.requests.closed > 0 && (
            <p className="mt-2 text-xs text-subtle-foreground" data-coordinator-closed>
              طلبات أُغلقت دون تطبيق (مرفوضة أو ملغاة): <span className="tabular-nums">{summary.data!.requests.closed}</span>
            </p>
          )}
          {summary.data!.family_count === 0 && (
            <p className="mt-2 text-xs text-muted-foreground" data-coordinator-no-families>
              لا توجد أسر ضمن نطاقك حاليًا.
            </p>
          )}
        </>
      )}

      <h3 className="mt-4 mb-1.5 text-xs font-medium text-subtle-foreground">إجراءات التنسيق</h3>
      <ul className="flex flex-col gap-2" data-coordinator-actions>
        <Action href="/family/coordinator" label="استعراض الأسر في النطاق" icon={Search} field="families" />
        <Action href={formFor("REGISTER_FAMILY")} label="طلب تسجيل أسرة جديدة" icon={FilePlus2} field="register-family" />
        <Action href={formFor("ADD_FAMILY_MEMBER")} label="طلب إضافة فرد إلى أسرة" icon={UserPlus} field="add-family-member" />
        <Action href="/family/coordinator/requests" label="طلبات التنسيق التي قدّمتها" icon={ClipboardList} field="requests" />
      </ul>
    </section>
  );
}
