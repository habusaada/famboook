"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, CheckCircle2, ChevronLeft, CircleAlert, Info, Lock, SearchX } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Code, DetailItem, DetailList, PageHeader, SectionHeader } from "@/components/shared/page-layout";
import { ChangeRequestActions, type ActionNotice } from "@/components/change-requests/change-request-actions";
import { ChangeRequestProposal } from "@/components/change-requests/change-request-comparison";
import { ChangeRequestStatusTag } from "@/components/change-requests/change-request-status";
import { ChangeRequestTimeline } from "@/components/change-requests/change-request-timeline";
import { ApiError } from "@/lib/api/client";
import { useChangeRequest } from "@/lib/api/change-requests";
import type { ChangeRequestApplyFailure, ChangeRequestDetail } from "@/lib/types/api/change-request";
import { formatDateTime } from "@/lib/utils/date";
import { applyFailureLabels, changeRequestTypeLabels, rejectionReasonLabels } from "@/lib/utils/change-request";

const when = (iso: string | null) => (iso ? formatDateTime(iso) : "—");

function Breadcrumb({ code }: { code?: string }) {
  return (
    <nav aria-label="مسار الصفحة" className="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/change-requests" className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
        طلبات تحديث البيانات
      </Link>
      {code && (
        <>
          <ChevronLeft className="size-3.5" aria-hidden />
          <Code className="text-foreground">{code}</Code>
        </>
      )}
    </nav>
  );
}

function Section({ title, description, children, ...props }: { title: string; description?: string; children: React.ReactNode } & React.ComponentProps<"section">) {
  return (
    <AppCard className="flex flex-col gap-3" {...props}>
      <SectionHeader title={title} description={description} />
      {children}
    </AppCard>
  );
}

function ApplyFailures({ request }: { request: ChangeRequestDetail }) {
  const { count, last_failed_at, last_code } = request.apply_failures;
  if (count === 0) return null;
  const label = last_code ? applyFailureLabels[last_code as ChangeRequestApplyFailure] ?? "سبب غير معروف" : null;

  return (
    <Alert variant={request.status === "APPROVED" ? "destructive" : "default"} data-apply-failures>
      <CircleAlert className="size-4" />
      <AlertTitle>
        محاولات تطبيق لم تنجح: <bdi className="tabular-nums">{count.toLocaleString("ar")}</bdi>
      </AlertTitle>
      <AlertDescription className="flex flex-col gap-0.5">
        {label && <span>آخر سبب: {label}</span>}
        {last_failed_at && <span>آخر محاولة: {formatDateTime(last_failed_at)}</span>}
        {request.status === "APPROVED" && <span>لم يتغير سجل الأسرة. يبقى الطلب معتمدًا بانتظار التطبيق.</span>}
      </AlertDescription>
    </Alert>
  );
}

function Notice({ notice }: { notice: ActionNotice | null }) {
  if (!notice) return null;
  return (
    <Alert role="status" data-action-notice>
      {notice.tone === "success" ? <CheckCircle2 className="size-4" /> : <Info className="size-4" />}
      <AlertDescription>{notice.message}</AlertDescription>
    </Alert>
  );
}

function DetailSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true">
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-48 w-full" />
    </div>
  );
}

/**
 * The Staff review workspace of one Change Request (PWA-5d). Everything
 * shown comes from GET /api/v1/change-requests/{uuid}: the proposal only
 * through the handler's presentation (never raw data), the timeline in the
 * server's order, the actions the server offers.
 */
export function ChangeRequestDetailView({ id }: { id: string }) {
  const { data: request, isLoading, isError, error, refetch, isFetching } = useChangeRequest(id);
  const [notice, setNotice] = useState<ActionNotice | null>(null);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Breadcrumb />
        <DetailSkeleton />
      </div>
    );
  }

  if (isError || !request) {
    const status = error instanceof ApiError ? error.status : 0;
    return (
      <div className="flex flex-col gap-4">
        <Breadcrumb />
        <AppCard padded={false}>
          {status === 404 ? (
            <EmptyState icon={SearchX} title="هذا الطلب غير متاح" description="قد يكون الرابط غير صحيح." />
          ) : status === 403 ? (
            <EmptyState icon={Lock} title="لا تملك صلاحية عرض هذا الطلب." />
          ) : (
            <div className="p-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل الطلب</AlertTitle>
                <AlertDescription className="flex flex-col gap-2">
                  <span>حدث خطأ أثناء الاتصال بالخادم.</span>
                  <Button variant="outline" size="sm" className="w-fit" onClick={() => refetch()}>
                    إعادة المحاولة
                  </Button>
                </AlertDescription>
              </Alert>
            </div>
          )}
        </AppCard>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4" data-change-request={request.id}>
      <PageHeader
        eyebrow={<Breadcrumb code={request.request_code} />}
        title={changeRequestTypeLabels[request.type] ?? "طلب تحديث"}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <Code>{request.request_code}</Code>
            <ChangeRequestStatusTag status={request.status} />
            {isFetching && <span className="text-xs text-subtle-foreground">جارٍ التحديث…</span>}
          </span>
        }
      />

      <Notice notice={notice} />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <Section title="التعديل المطلوب" description="البيانات الحالية في السجل مقابل ما تطلبه الأسرة." data-proposal>
            <ChangeRequestProposal typeAvailable={request.type_available} presentation={request.presentation} />
          </Section>

          {request.reason && (
            <Section title="سبب الطلب" description="كما كتبته الأسرة." data-reason>
              <p className="break-words whitespace-pre-line text-sm text-foreground">{request.reason}</p>
            </Section>
          )}

          <Section title="مسار الطلب" description="الأحداث بالترتيب الزمني.">
            <ChangeRequestTimeline events={request.timeline} />
          </Section>
        </div>

        <div className="flex flex-col gap-4">
          <Section title="الإجراءات" data-actions-section>
            <ApplyFailures request={request} />
            <ChangeRequestActions request={request} onNotice={setNotice} />
          </Section>

          <Section title="بيانات الطلب">
            <DetailList className="sm:grid-cols-1">
              <DetailItem label="رقم الطلب" ltr>
                {request.request_code}
              </DetailItem>
              <DetailItem label="الأسرة">
                <Link
                  href={`/families/${encodeURIComponent(request.family.family_code)}`}
                  className="rounded-sm text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                  data-family-link
                >
                  <Code>{request.family.family_code}</Code>
                </Link>
              </DetailItem>
              <DetailItem label="رب الأسرة" muted={!request.family.household_head_name}>
                {request.family.household_head_name ?? "غير محدد"}
              </DetailItem>
              {request.target_person && (
                <DetailItem label="الفرد المعني">
                  <Link
                    href={`/people/${encodeURIComponent(request.target_person.person_code)}`}
                    className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    {request.target_person.full_name}
                  </Link>
                </DetailItem>
              )}
              <DetailItem label="مقدّم الطلب" muted={!request.submitted_by.name}>
                {request.submitted_by.name ?? "غير معروف"}
              </DetailItem>
              <DetailItem label="تاريخ التقديم">{when(request.submitted_at)}</DetailItem>
              {request.review.reviewed_at && (
                <DetailItem label="بدء المراجعة">
                  {when(request.review.reviewed_at)}
                  {request.review.reviewed_by?.name && <span className="block text-xs text-muted-foreground">{request.review.reviewed_by.name}</span>}
                </DetailItem>
              )}
              {request.review.approved_at && (
                <DetailItem label="الاعتماد">
                  {when(request.review.approved_at)}
                  {request.review.approved_by?.name && <span className="block text-xs text-muted-foreground">{request.review.approved_by.name}</span>}
                </DetailItem>
              )}
              {request.review.applied_at && (
                <DetailItem label="التطبيق على السجل">
                  {when(request.review.applied_at)}
                  {request.review.applied_by?.name && <span className="block text-xs text-muted-foreground">{request.review.applied_by.name}</span>}
                </DetailItem>
              )}
              {request.review.cancelled_at && <DetailItem label="الإلغاء">{when(request.review.cancelled_at)}</DetailItem>}
            </DetailList>
          </Section>

          {request.rejection && (
            <Section title="الرفض" data-rejection>
              <DetailList className="sm:grid-cols-1">
                <DetailItem label="السبب">{rejectionReasonLabels[request.rejection.reason_code] ?? "سبب غير معروف"}</DetailItem>
                <DetailItem label="التاريخ">{when(request.rejection.rejected_at)}</DetailItem>
                {request.rejection.rejected_by?.name && <DetailItem label="بواسطة">{request.rejection.rejected_by.name}</DetailItem>}
              </DetailList>
              {request.rejection.message && (
                <div className="rounded-control bg-surface-2 px-3 py-2 text-sm">
                  <span className="block text-xs font-medium text-muted-foreground">رسالة الرفض للأسرة</span>
                  <p className="break-words whitespace-pre-line">{request.rejection.message}</p>
                </div>
              )}
            </Section>
          )}
        </div>
      </div>
    </div>
  );
}
