"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, CheckCircle2, Clock, Eye, Info, RotateCw, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AnnouncementLevelBadge } from "@/components/announcements/announcement-parts";
import { FollowupCloseDialog, FollowupContactForm, type FollowupNotice } from "@/components/followups/followup-actions";
import { FollowupStageBadge, FollowupStateBadge, when } from "@/components/followups/followup-parts";
import { AppCard } from "@/components/shared/app-card";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { ApiError } from "@/lib/api/client";
import { type FollowupDetail, type FollowupEvent, type FollowupSide, useFollowupHistoryQuery, useFollowupQuery } from "@/lib/api/followups";
import {
  closeReasonLabels,
  eventActorLabel,
  eventReasonLabel,
  eventTypeLabels,
  followupErrorMessage,
  followupOutcomeLabels,
  followupStageLabels,
  followupTriggerLabels,
  isPastMessageExpiry,
} from "@/lib/utils/followup";

const fmt = (n: number) => n.toLocaleString("ar");

function BackLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="-ms-1 inline-flex min-h-9 w-fit items-center gap-1 rounded-md px-1 text-[13px] font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
    >
      <ArrowRight className="size-4" aria-hidden />
      {label}
    </Link>
  );
}

function Section({ title, description, children, field }: { title: string; description?: string; children: React.ReactNode; field: string }) {
  return (
    <AppCard variant="flat" className="flex flex-col gap-3" data-followup-section={field}>
      <SectionHeader title={title} description={description} />
      {children}
    </AppCard>
  );
}

function Loading() {
  return (
    <div className="flex flex-col gap-4" data-followup-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل المتابعة
      </p>
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-32 w-full rounded-lg" />
      <Skeleton className="h-40 w-full rounded-lg" />
    </div>
  );
}

const NOTICE_TONES: Record<FollowupNotice["tone"], string> = {
  success: "bg-success-soft text-success",
  info: "bg-surface-2 text-foreground",
  warning: "bg-warning-soft text-warning",
};

function HistoryItem({ event }: { event: FollowupEvent }) {
  const reason = eventReasonLabel(event);
  const outcome = event.type === "FOLLOWUP_CLOSED" && event.outcome ? followupOutcomeLabels[event.outcome] ?? event.outcome : null;
  return (
    <li className="flex flex-col gap-1 border-b border-border/70 py-3 last:border-0" data-followup-event={event.type}>
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <span className="text-sm font-semibold text-foreground">{eventTypeLabels[event.type] ?? event.type}</span>
        {event.occurred_at && <time className="text-xs text-muted-foreground" dateTime={event.occurred_at}>{when(event.occurred_at)}</time>}
      </div>
      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted-foreground">
        <span data-followup-event-actor>
          <bdi>{eventActorLabel(event)}</bdi>
        </span>
        {event.stage && (
          <>
            <span aria-hidden>·</span>
            <span>{followupStageLabels[event.stage] ?? event.stage}</span>
          </>
        )}
        {reason && (
          <>
            <span aria-hidden>·</span>
            <span className="font-medium text-foreground">{reason}</span>
          </>
        )}
        {outcome && (
          <>
            <span aria-hidden>·</span>
            <span>{outcome}</span>
          </>
        )}
      </p>
      {event.note && (
        <p dir="auto" className="rounded-md bg-surface-2 px-3 py-2 text-sm leading-relaxed break-words whitespace-pre-line text-foreground" data-followup-event-note>
          {event.note}
        </p>
      )}
    </li>
  );
}

function History({ side, id }: { side: FollowupSide; id: string }) {
  const query = useFollowupHistoryQuery(side, id);
  if (query.isPending) return <Skeleton className="h-24 w-full rounded-lg" />;
  if (query.isError) {
    return (
      <Alert variant="destructive" data-followup-history-error>
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل السجل</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-2">
          {followupErrorMessage(query.error)}
          <Button size="sm" variant="outline" className="gap-1.5" onClick={() => query.refetch()} disabled={query.isFetching}>
            <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
            إعادة المحاولة
          </Button>
        </AlertDescription>
      </Alert>
    );
  }
  if (query.data.length === 0) return <p className="text-sm text-muted-foreground">لا توجد أحداث بعد.</p>;
  return (
    <ol className="flex flex-col" aria-label="سجل المتابعة" data-followup-history>
      {query.data.map((event, i) => (
        <HistoryItem key={`${event.type}-${event.occurred_at ?? ""}-${i}`} event={event} />
      ))}
    </ol>
  );
}

/**
 * One follow-up (FB-NOTIFY-01E.3-F3.3), for either side: the family (code and
 * household head only), the message, the stage and state, the recipient's own
 * view / acknowledgement, the history and — when `manage` allows it (the
 * caller passes the SERVER's answer: Staff permission, coordinator
 * `can_manage`) and the follow-up is OPEN and not past the message expiry —
 * recording a contact and closing manually. The server decides every write.
 */
export function FollowupDetailView({
  side,
  id,
  listHref,
  listLabel,
  manage,
  readOnlyNotice,
  extraDetails,
}: {
  side: FollowupSide;
  id: string;
  listHref: string;
  listLabel: string;
  manage: (followup: FollowupDetail) => boolean;
  /** Why an OPEN follow-up is read-only for this reader. */
  readOnlyNotice: string;
  extraDetails?: (followup: FollowupDetail) => React.ReactNode;
}) {
  const query = useFollowupQuery(side, id);
  const [notice, setNotice] = useState<FollowupNotice | null>(null);

  if (!query.data) {
    if (query.isError) {
      const missing = query.error instanceof ApiError && query.error.status === 404;
      return (
        <div className="flex flex-col gap-4">
          <BackLink href={listHref} label={listLabel} />
          <AppCard variant="flat" className="flex flex-col items-center gap-3 py-8 text-center" data-followup-error={missing ? "missing" : "failed"}>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-foreground" role="alert">
              {missing ? "هذه المتابعة غير متاحة." : followupErrorMessage(query.error)}
            </p>
            {missing ? (
              <Button asChild variant="outline">
                <Link href={listHref}>العودة إلى {listLabel}</Link>
              </Button>
            ) : (
              <Button variant="outline" className="gap-1.5" onClick={() => query.refetch()} disabled={query.isFetching}>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                إعادة المحاولة
              </Button>
            )}
          </AppCard>
        </div>
      );
    }
    return <Loading />;
  }

  const followup = query.data;
  const expired = isPastMessageExpiry(followup);
  const open = followup.status === "OPEN";
  const manageable = open && !expired && manage(followup);

  return (
    <article className="flex flex-col gap-4" aria-labelledby="followup-title" data-followup-detail={followup.id}>
      <header className="flex flex-col gap-2">
        <BackLink href={listHref} label={listLabel} />
        <div className="flex flex-wrap items-center gap-2">
          <FollowupStateBadge followup={followup} />
          <FollowupStageBadge stage={followup.stage} />
          {followup.message.level === "IMPORTANT" && <AnnouncementLevelBadge level="IMPORTANT" />}
        </div>
        <h1 id="followup-title" className="flex flex-wrap items-baseline gap-x-2 text-2xl leading-tight font-bold break-words text-foreground">
          <bdi>{followup.family.head_name ?? "—"}</bdi>
          {followup.family.family_code && <Code className="text-base text-brand-800">{followup.family.family_code}</Code>}
        </h1>
        <p className="text-sm text-muted-foreground">{followupTriggerLabels[followup.trigger_reason] ?? followup.trigger_reason}</p>
      </header>

      {notice && (
        <div role="status" className={`flex items-start gap-2 rounded-lg px-3.5 py-3 text-sm ${NOTICE_TONES[notice.tone]}`} data-followup-notice={notice.tone}>
          {notice.tone === "warning" ? <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> : <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />}
          <span className="flex-1">{notice.message}</span>
          <button type="button" onClick={() => setNotice(null)} aria-label="إغلاق التنبيه" className="rounded p-0.5 hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-ring">
            <X className="size-4" aria-hidden />
          </button>
        </div>
      )}

      {expired && (
        <Alert data-followup-expired>
          <Clock className="size-4" />
          <AlertTitle>انتهت مدة الإعلان</AlertTitle>
          <AlertDescription>لم تعد هذه المتابعة قابلة للإدارة، ويغلقها النظام تلقائيًا بنتيجة «انتهت مدة الإعلان».</AlertDescription>
        </Alert>
      )}

      {manageable ? (
        <Section title="إدارة المتابعة" description="سجّل كل محاولة تواصل مع الأسرة. التواصل لا يغلق المتابعة ولا يُعدّ اطلاعًا أو تأكيدًا من الأسرة." field="manage">
          <FollowupContactForm side={side} id={followup.id} onNotice={setNotice} />
          <div className="border-t border-border/70 pt-3">
            <FollowupCloseDialog side={side} id={followup.id} onNotice={setNotice} />
          </div>
        </Section>
      ) : (
        open &&
        !expired && (
          <p className="flex items-start gap-2 rounded-lg bg-surface-2 px-3.5 py-3 text-sm text-foreground" role="note" data-followup-read-only>
            <Eye className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
            <span>{readOnlyNotice}</span>
          </p>
        )
      )}

      <Section title="المتابعة" field="followup">
        <DetailList>
          <DetailItem label="المرحلة">{followupStageLabels[followup.stage] ?? followup.stage}</DetailItem>
          <DetailItem label="مستحقة منذ">{when(followup.due_at)}</DetailItem>
          <DetailItem label="فُتحت">{when(followup.opened_at)}</DetailItem>
          <DetailItem label="أُغلقت">{when(followup.closed_at)}</DetailItem>
          <DetailItem label="النتيجة">{followup.outcome ? followupOutcomeLabels[followup.outcome] ?? followup.outcome : "—"}</DetailItem>
          <DetailItem label="سبب الإغلاق">{followup.close_reason ? closeReasonLabels[followup.close_reason] ?? followup.close_reason : "—"}</DetailItem>
          <DetailItem label="محاولات التواصل المسجّلة">{fmt(followup.contacts)}</DetailItem>
          {extraDetails?.(followup)}
        </DetailList>
      </Section>

      <Section title="الإعلان" field="message">
        <DetailList>
          <DetailItem label="رمز الإعلان" ltr>
            {followup.message.notice_code ?? "—"}
          </DetailItem>
          <DetailItem label="العنوان">
            <bdi>{followup.message.title ?? "—"}</bdi>
          </DetailItem>
          <DetailItem label="المستوى">{followup.message.level === "IMPORTANT" ? "مهم" : "عادي"}</DetailItem>
          <DetailItem label="ينتهي">{when(followup.message.expires_at)}</DetailItem>
        </DetailList>
      </Section>

      <Section title="استجابة الأسرة" description="من تصرّف الأسرة نفسها فقط؛ التواصل والإغلاق اليدوي لا يغيّرانها." field="recipient">
        <DetailList>
          <DetailItem label="اطّلعت على الإعلان">{when(followup.recipient.viewed_at)}</DetailItem>
          <DetailItem label="أكّدت الاطلاع">
            {followup.recipient.acknowledged_at ? (
              <span className="flex flex-col items-end gap-0.5">
                {when(followup.recipient.acknowledged_at)}
                {followup.recipient.acknowledged_late && <span className="text-xs text-muted-foreground">بعد انتهاء مدة الإعلان</span>}
              </span>
            ) : (
              "—"
            )}
          </DetailItem>
          <DetailItem label="حساب مفعّل عند النشر">{followup.recipient.had_family_account ? "نعم" : "لا"}</DetailItem>
        </DetailList>
      </Section>

      <Section title="السجل" field="history">
        <History side={side} id={followup.id} />
      </Section>
    </article>
  );
}
