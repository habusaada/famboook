"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, CheckCircle2, Info, RotateCw, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { ChangeRequestComparison, comparisonRows } from "@/components/change-requests/change-request-comparison";
import { CancelDialog, type FamilyActionNotice, type RequestActionCopy, ResubmitDialog } from "@/components/family/requests/family-request-actions";
import { FamilyRequestStatus, FamilyRequestTimeline, FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import {
  type CoordinatorRequest,
  coordinatorRequestErrorMessage,
  useCoordinatorRequestMutation,
  useCoordinatorRequestQuery,
} from "@/lib/api/coordinator-requests";
import { rejectionReasonLabels } from "@/lib/utils/change-request";
import { coordinatorRequestStatusDescriptions, coordinatorRequestTypeLabel } from "@/lib/utils/coordinator-request";
import { formatDateTime, formatTimestampDate } from "@/lib/utils/date";

const BACK = { href: "/family/coordinator/requests", label: "طلبات التنسيق" };

/** The dialogs' wording for a request about another household (CO-02.6b). */
const COORDINATOR_COPY: RequestActionCopy = {
  resubmitDone: "أُرسل الاستكمال إلى فريق المراجعة.",
  cancelDone: "أُلغي الطلب. لم يتغير شيء في السجل.",
  cancelDescription: "يوقف الإلغاء مراجعة هذا الطلب نهائيًا، ولا يغيّر شيئًا في السجل. يمكنك تقديم طلب جديد لاحقًا عند الحاجة.",
  errorMessage: coordinatorRequestErrorMessage,
};

function Section({ title, children, field }: { title: string; children: React.ReactNode; field: string }) {
  const id = `coordinator-request-${field}`;
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-labelledby={id} data-coordinator-request-section={field}>
      <h2 id={id} className="mb-3 text-base font-semibold text-foreground">
        {title}
      </h2>
      {children}
    </section>
  );
}

function Note({ children }: { children: React.ReactNode }) {
  return (
    <p className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-muted-foreground">
      <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span>{children}</span>
    </p>
  );
}

/** The proposal, only in the approved display-ready shape {rows:[{label,current,proposed}]}. */
function Proposal({ request }: { request: CoordinatorRequest }) {
  if (request.presentation === null || request.presentation === undefined) {
    return <Note>تفاصيل الطلب غير متاحة للعرض حاليًا.</Note>;
  }
  const rows = comparisonRows(request.presentation);
  if (!rows) return <Note>عرض تفاصيل هذا النوع من الطلبات غير مدعوم في هذه الواجهة بعد.</Note>;
  return <ChangeRequestComparison rows={rows} applied={request.status === "APPLIED"} />;
}

/** The Family the request is about — or, for a proposed Family, its placement and (after APPLY) its code. */
function Subject({ request }: { request: CoordinatorRequest }) {
  const familyLink = (code: string) => (
    <Link href={`/family/coordinator/families/${encodeURIComponent(code)}`} className="font-mono font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring" dir="ltr">
      {code}
    </Link>
  );

  return (
    <dl className="grid gap-3 text-sm sm:grid-cols-2" data-coordinator-request-subject>
      {request.type === "REGISTER_FAMILY" ? (
        <>
          <div>
            <dt className="text-xs text-muted-foreground">العائلة</dt>
            <dd className="text-foreground">
              <bdi>{request.placement?.clan.name ?? request.placement?.clan.code ?? "—"}</bdi>
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">الفرع</dt>
            <dd className="text-foreground">
              <bdi>{request.placement?.branch ? (request.placement.branch.name ?? request.placement.branch.code) : "دون فرع"}</bdi>
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">رمز الأسرة</dt>
            <dd className="text-foreground">{request.created_family_code ? familyLink(request.created_family_code) : "يُنشأ عند تطبيق الطلب"}</dd>
          </div>
        </>
      ) : (
        <div>
          <dt className="text-xs text-muted-foreground">الأسرة</dt>
          <dd className="text-foreground">{request.family_code ? familyLink(request.family_code) : "—"}</dd>
        </div>
      )}
      {request.summary && (
        <div>
          <dt className="text-xs text-muted-foreground">{request.type === "REGISTER_FAMILY" ? "رب الأسرة المقترح" : "الفرد المقترح"}</dt>
          <dd className="break-words text-foreground">
            <bdi>{request.summary}</bdi>
          </dd>
        </div>
      )}
    </dl>
  );
}

function Outcome({ request }: { request: CoordinatorRequest }) {
  if (request.status === "APPLIED") {
    return (
      <Section title="نتيجة الطلب" field="outcome">
        <p className="flex items-start gap-2 text-sm text-foreground">
          <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
          طُبّق الطلب على السجل{request.applied_at ? ` بتاريخ ${formatTimestampDate(request.applied_at)}` : ""}.
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
      </Section>
    );
  }
  return null;
}

/**
 * One of the Coordinator's requests (CO-02.6b): status, subject, the masked
 * proposal, the family-visible timeline with the reviewers' messages, the
 * outcome, and the actions the server offers (available_actions) — a text
 * reply to a clarification and cancellation. The proposal itself is never
 * edited: the API's resubmission takes a reply only (ADR §15).
 */
export function CoordinatorRequestDetail({ id }: { id: string }) {
  const query = useCoordinatorRequestQuery(id);
  const mutation = useCoordinatorRequestMutation(id);
  const [notice, setNotice] = useState<FamilyActionNotice | null>(null);

  if (!query.data) {
    if (query.isError) {
      const missing = query.error instanceof ApiError && query.error.status === 404;
      return (
        <div className="flex flex-col gap-5">
          <FamilyRequestsHeader back={BACK} title={missing ? "الطلب غير متاح" : "تعذّر تحميل الطلب"} />
          <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-request-error={missing ? "missing" : "failed"}>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-foreground" role="alert">
              {missing ? "لم نجد هذا الطلب ضمن طلباتك في نطاقك الحالي." : coordinatorRequestErrorMessage(query.error)}
            </p>
            {missing ? (
              <Button asChild variant="outline" className="h-10 rounded-xl">
                <Link href={BACK.href}>العودة إلى طلبات التنسيق</Link>
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
    return (
      <div className="flex flex-col gap-4" data-coordinator-request-loading>
        <p role="status" className="sr-only">
          جارٍ تحميل الطلب
        </p>
        <Skeleton className="h-8 w-48 rounded-lg" />
        <Skeleton className="h-28 w-full rounded-2xl" />
        <Skeleton className="h-40 w-full rounded-2xl" />
      </div>
    );
  }

  const request = query.data;
  const canResubmit = request.available_actions.includes("resubmit");
  const canCancel = request.available_actions.includes("cancel");

  return (
    <div className="flex flex-col gap-4" data-coordinator-request-detail={request.id}>
      <FamilyRequestsHeader back={BACK} title={coordinatorRequestTypeLabel(request.type)} />

      {notice && (
        <div
          role="status"
          className={`flex items-start gap-2 rounded-xl px-3.5 py-3 text-sm ${notice.tone === "success" ? "bg-success-soft text-success" : "bg-surface-2 text-foreground"}`}
          data-coordinator-notice={notice.tone}
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
        <p className="mt-2 text-sm text-foreground" data-coordinator-status-description>
          {coordinatorRequestStatusDescriptions[request.status]}
        </p>
        {request.submitted_at && <p className="mt-1 text-xs text-muted-foreground tabular-nums">قُدّم في {formatDateTime(request.submitted_at)}</p>}
      </Section>

      {(canResubmit || canCancel) && (
        <Section title="الإجراء المطلوب" field="action">
          {canResubmit && <p className="mb-3 text-sm text-foreground">طلب فريق المراجعة معلومات إضافية. اقرأ رسالته في مسار الطلب ثم أرسل الاستكمال نصًا؛ لا تتغير بيانات الطلب نفسها.</p>}
          <div className="flex flex-col gap-2 sm:flex-row">
            {canResubmit && <ResubmitDialog mutation={mutation} onDone={setNotice} copy={COORDINATOR_COPY} />}
            {canCancel && <CancelDialog mutation={mutation} onDone={setNotice} copy={COORDINATOR_COPY} />}
          </div>
        </Section>
      )}

      <Outcome request={request} />

      <Section title={request.type === "REGISTER_FAMILY" ? "الأسرة المقترحة" : "الأسرة"} field="subject">
        <Subject request={request} />
      </Section>

      {request.reason && (
        <Section title="سبب الطلب" field="reason">
          <p className="break-words whitespace-pre-line text-sm text-foreground">{request.reason}</p>
        </Section>
      )}

      <Section title="تفاصيل الطلب" field="proposal">
        <Proposal request={request} />
      </Section>

      <Section title="مسار الطلب وملاحظات المراجعة" field="timeline">
        <FamilyRequestTimeline events={request.timeline} />
      </Section>
    </div>
  );
}
