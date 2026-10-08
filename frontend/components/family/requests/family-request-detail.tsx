"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, CheckCircle2, Info, RotateCw, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { ChangeRequestComparison, comparisonRows } from "@/components/change-requests/change-request-comparison";
import { CancelDialog, type FamilyActionNotice, ResubmitDialog } from "@/components/family/requests/family-request-actions";
import { FamilyRequestStatus, FamilyRequestTimeline, FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { type FamilyChangeRequest, useFamilyChangeRequestMutation, useFamilyChangeRequestQuery } from "@/lib/api/family-change-requests";
import { isAccessFailure } from "@/lib/api/family-household";
import { changeRequestTypeLabels, rejectionReasonLabels } from "@/lib/utils/change-request";
import { formatDateTime, formatTimestampDate } from "@/lib/utils/date";
import { familyRequestStatusDescriptions } from "@/lib/utils/family-change-request";

const BACK = { href: "/family/requests", label: "طلباتي" };

function Section({ title, children, field }: { title: string; children: React.ReactNode; field: string }) {
  const id = `family-request-${field}`;
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-labelledby={id} data-family-request-section={field}>
      <h2 id={id} className="mb-3 text-base font-semibold text-foreground">
        {title}
      </h2>
      {children}
    </section>
  );
}

function Note({ children }: { children: React.ReactNode }) {
  return (
    <p className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-muted-foreground" data-family-proposal-note>
      <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span>{children}</span>
    </p>
  );
}

/**
 * The proposal, only in the approved display-ready shape
 * {rows:[{label,current,proposed}]}. Anything else is never interpreted.
 */
function Proposal({ request }: { request: FamilyChangeRequest }) {
  if (request.presentation === null || request.presentation === undefined) {
    return <Note>تفاصيل التعديل المطلوب غير متاحة للعرض حاليًا.</Note>;
  }
  const rows = comparisonRows(request.presentation);
  if (!rows) return <Note>عرض تفاصيل هذا النوع من الطلبات غير مدعوم في هذه الواجهة بعد.</Note>;
  return <ChangeRequestComparison rows={rows} />;
}

function Outcome({ request }: { request: FamilyChangeRequest }) {
  if (request.status === "APPLIED") {
    return (
      <Section title="نتيجة الطلب" field="outcome">
        <p className="flex items-start gap-2 text-sm text-foreground">
          <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
          طُبّق التعديل على سجل أسرتك{request.applied_at ? ` بتاريخ ${formatTimestampDate(request.applied_at)}` : ""}.
        </p>
      </Section>
    );
  }
  if (request.status === "REJECTED" && request.rejection) {
    return (
      <Section title="نتيجة الطلب" field="outcome">
        <dl className="flex flex-col gap-2 text-sm">
          <div>
            <dt className="text-xs text-muted-foreground">السبب</dt>
            <dd className="text-foreground">{rejectionReasonLabels[request.rejection.reason_code] ?? "سبب آخر"}</dd>
          </div>
          {request.rejection.message && (
            <div>
              <dt className="text-xs text-muted-foreground">توضيح من فريق المراجعة</dt>
              <dd className="break-words whitespace-pre-line text-foreground">{request.rejection.message}</dd>
            </div>
          )}
          <div>
            <dt className="text-xs text-muted-foreground">تاريخ القرار</dt>
            <dd className="text-foreground tabular-nums">{formatTimestampDate(request.rejection.rejected_at)}</dd>
          </div>
        </dl>
        <p className="mt-3 text-[13px] text-muted-foreground">لم يتغير سجل أسرتك. يمكنك تقديم طلب جديد عند الحاجة.</p>
      </Section>
    );
  }
  return null;
}

function Loading() {
  return (
    <div className="flex flex-col gap-4" data-family-request-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل الطلب
      </p>
      <Skeleton className="h-8 w-48 rounded-lg" />
      <Skeleton className="h-28 w-full rounded-2xl" />
      <Skeleton className="h-40 w-full rounded-2xl" />
    </div>
  );
}

/**
 * One request of the Family (PWA-5f): status, reason, the proposal, the
 * family-visible timeline, the action required and the final outcome.
 * Actions are offered only when the server lists them in available_actions.
 */
export function FamilyRequestDetail({ id }: { id: string }) {
  const query = useFamilyChangeRequestQuery(id);
  const mutation = useFamilyChangeRequestMutation(id);
  const [notice, setNotice] = useState<FamilyActionNotice | null>(null);

  if (!query.data) {
    if (query.isError) {
      if (isAccessFailure(query.error)) return null;
      const missing = query.error instanceof ApiError && query.error.status === 404;
      return (
        <div className="flex flex-col gap-5">
          <FamilyRequestsHeader back={BACK} title={missing ? "الطلب غير متاح" : "تعذّر تحميل الطلب"} />
          <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-request-error={missing ? "missing" : "failed"}>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-foreground" role="alert">
              {missing ? "لم نجد هذا الطلب ضمن طلبات أسرتك." : "حدث خطأ أثناء تحميل الطلب."}
            </p>
            {missing ? (
              <Button asChild variant="outline" className="h-10 rounded-xl">
                <Link href="/family/requests">العودة إلى طلباتي</Link>
              </Button>
            ) : (
              <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                إعادة المحاولة
              </Button>
            )}
          </section>
        </div>
      );
    }
    return <Loading />;
  }

  const request = query.data;
  const canResubmit = request.available_actions.includes("resubmit");
  const canCancel = request.available_actions.includes("cancel");
  const done = (n: FamilyActionNotice) => setNotice(n);

  return (
    <div className="flex flex-col gap-4" data-family-request-detail={request.id}>
      <FamilyRequestsHeader back={BACK} title={changeRequestTypeLabels[request.type] ?? "طلب تحديث"} />

      {notice && (
        <div
          role="status"
          className={`flex items-start gap-2 rounded-xl px-3.5 py-3 text-sm ${notice.tone === "success" ? "bg-success-soft text-success" : "bg-surface-2 text-foreground"}`}
          data-family-notice={notice.tone}
        >
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span className="flex-1">{notice.message}</span>
          <button type="button" onClick={() => setNotice(null)} aria-label="إغلاق التنبيه" className="rounded p-0.5 hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-ring">
            <X className="size-4" aria-hidden />
          </button>
        </div>
      )}

      <Section title="حالة الطلب" field="status">
        <div className="flex flex-wrap items-center gap-2">
          <FamilyRequestStatus status={request.status} />
          <bdi dir="ltr" className="font-mono text-xs tracking-wide text-muted-foreground">
            {request.request_code}
          </bdi>
        </div>
        <p className="mt-2 text-sm text-foreground" data-family-status-description>
          {familyRequestStatusDescriptions[request.status]}
        </p>
        {request.submitted_at && <p className="mt-1 text-xs text-muted-foreground tabular-nums">قُدّم في {formatDateTime(request.submitted_at)}</p>}
      </Section>

      {(canResubmit || canCancel) && (
        <Section title="الإجراء المطلوب" field="action">
          {canResubmit && <p className="mb-3 text-sm text-foreground">طلب فريق المراجعة معلومات إضافية. اقرأ رسالته في مسار الطلب ثم أرسل الاستكمال.</p>}
          <div className="flex flex-col gap-2 sm:flex-row">
            {canResubmit && <ResubmitDialog mutation={mutation} onDone={done} />}
            {canCancel && <CancelDialog mutation={mutation} onDone={done} />}
          </div>
        </Section>
      )}

      <Outcome request={request} />

      {request.reason && (
        <Section title="سبب الطلب" field="reason">
          <p className="break-words whitespace-pre-line text-sm text-foreground">{request.reason}</p>
        </Section>
      )}

      <Section title="التعديل المطلوب" field="proposal">
        <Proposal request={request} />
      </Section>

      <Section title="مسار الطلب" field="timeline">
        <FamilyRequestTimeline events={request.timeline} />
      </Section>
    </div>
  );
}
