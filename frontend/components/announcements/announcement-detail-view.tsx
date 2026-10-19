"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, CheckCircle2, Info, Loader2, RotateCw, Send, X, XCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  AnnouncementActionDialog,
  AnnouncementLevelBadge,
  type AnnouncementNotice,
  AnnouncementStatisticsPanel,
  AnnouncementStatusBadge,
} from "@/components/announcements/announcement-parts";
import { AppCard } from "@/components/shared/app-card";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import {
  type AnnouncementAction,
  type AnnouncementDetail,
  type AnnouncementSide,
  announcementPollInterval,
  isPublicationActive,
  useAnnouncementQuery,
} from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";
import { formatDateTime } from "@/lib/utils/date";
import {
  announcementErrorMessage,
  audienceSelectionLabels,
  audienceTypeLabels,
  failureExplanation,
} from "@/lib/utils/announcement";

const fmt = (n: number) => n.toLocaleString("ar");
const when = (iso: string | null) => (iso ? formatDateTime(iso) : "—");

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
    <AppCard variant="flat" className="flex flex-col gap-3" data-announcement-section={field}>
      <SectionHeader title={title} description={description} />
      {children}
    </AppCard>
  );
}

export const ANNOUNCEMENT_ACTION_COPY: Record<AnnouncementAction, Parameters<typeof AnnouncementActionDialog>[0]["copy"]> = {
  PUBLISH: {
    trigger: "طلب النشر",
    title: "طلب نشر الإعلان؟",
    description:
      "يُضاف الإعلان إلى قائمة انتظار النشر. لا يصل إلى الأسر إلا بعد اكتمال النشر على الخادم، وقد يستغرق ذلك بعض الوقت. إن كان النشر غير متاح حاليًا فلن يتغير شيء.",
    confirm: "طلب النشر",
    success: "طُلب نشر الإعلان وهو الآن في انتظار النشر. لم يُنشر بعد.",
    replayed: "لم يتغير شيء: سبق طلب نشر هذا الإعلان.",
    icon: <Send className="size-4" aria-hidden />,
  },
  RETRY: {
    trigger: "إعادة محاولة النشر",
    title: "إعادة محاولة النشر؟",
    description: "تتاح إعادة المحاولة اليدوية مرة واحدة فقط لهذا الإعلان. يُضاف إلى قائمة الانتظار من جديد. إن كان النشر غير متاح حاليًا فلن يتغير شيء.",
    confirm: "إعادة المحاولة",
    success: "أُعيد الإعلان إلى قائمة انتظار النشر. لم يُنشر بعد.",
    replayed: "لم يتغير شيء: الإعلان في انتظار النشر بالفعل.",
    icon: <RotateCw className="size-4" aria-hidden />,
  },
  CANCEL: {
    trigger: "إلغاء الإعلان",
    title: "إلغاء الإعلان؟",
    description: "يُلغى الإعلان نهائيًا ولن يُنشر. يبقى في سجلك بحالة «ملغى».",
    confirm: "إلغاء الإعلان",
    success: "أُلغي الإعلان. لن يُنشر.",
    replayed: "لم يتغير شيء: سبق إلغاء هذا الإعلان.",
    destructive: true,
    icon: <XCircle className="size-4" aria-hidden />,
  },
};

function Loading() {
  return (
    <div className="flex flex-col gap-4" data-announcement-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل الإعلان
      </p>
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-32 w-full rounded-lg" />
      <Skeleton className="h-40 w-full rounded-lg" />
    </div>
  );
}

function Selection({ announcement }: { announcement: AnnouncementDetail }) {
  const entries = Array.isArray(announcement.audience_selection) ? [] : Object.entries(announcement.audience_selection);
  return (
    <DetailList>
      <DetailItem label="نوع المستلمين">{announcement.audience_type ? audienceTypeLabels[announcement.audience_type] ?? announcement.audience_type : "—"}</DetailItem>
      {entries.map(([key, count]) => (
        <DetailItem key={key} label={audienceSelectionLabels[key] ?? "عناصر محددة"}>
          {fmt(count)}
        </DetailItem>
      ))}
      <DetailItem label="المستلمون عند النشر">
        {announcement.recipient_count_at_publication === null ? "—" : fmt(announcement.recipient_count_at_publication)}
      </DetailItem>
    </DetailList>
  );
}

/**
 * One of the account's OWN announcements (FB-NOTIFY-01C.3c / 3d), for either
 * side: content, audience counts, lifecycle, publication attempts and the
 * server's statistics — nothing per family. Actions are offered only when
 * the STATE allows them (available_actions) AND the caller's `actionsFor`
 * (permissions, and for a coordinator the current scope) keeps them; the
 * server still decides. While a publication attempt is active the detail is
 * polled (bounded, progressive); 202 is "queued", never "published".
 */
export function AnnouncementDetailView({
  side,
  id,
  listHref,
  listLabel,
  actionsFor,
  noPublishNotice,
  banner,
}: {
  side: AnnouncementSide;
  id: string;
  listHref: string;
  listLabel: string;
  actionsFor: (announcement: AnnouncementDetail) => AnnouncementAction[];
  /** Shown on a DRAFT whose publication this account may not request now. */
  noPublishNotice: string;
  /** Side-specific context above the content (e.g. no current coordinator scope). */
  banner?: React.ReactNode;
}) {
  const query = useAnnouncementQuery(side, id);
  const [notice, setNotice] = useState<AnnouncementNotice | null>(null);

  if (!query.data) {
    if (query.isError) {
      const missing = query.error instanceof ApiError && query.error.status === 404;
      return (
        <div className="flex flex-col gap-4">
          <BackLink href={listHref} label={listLabel} />
          <AppCard variant="flat" className="flex flex-col items-center gap-3 py-8 text-center" data-announcement-error={missing ? "missing" : "failed"}>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-foreground" role="alert">
              {missing ? "هذا الإعلان غير متاح." : announcementErrorMessage(query.error)}
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

  const announcement = query.data;
  const actions = actionsFor(announcement);
  const active = isPublicationActive(announcement.status);
  // The same rule TanStack Query applies; false while active = the bounded window ended.
  const polling = announcementPollInterval(announcement) !== false;
  const failure = failureExplanation(announcement.publish_failure_code);

  return (
    <article className="flex flex-col gap-4" aria-labelledby="announcement-title" data-announcement-detail={announcement.id}>
      <header className="flex flex-col gap-2">
        <BackLink href={listHref} label={listLabel} />
        <div className="flex flex-wrap items-center gap-2">
          <Code className="text-sm text-brand-800">{announcement.notice_code}</Code>
          <AnnouncementStatusBadge status={announcement.status} />
          <AnnouncementLevelBadge level={announcement.level} />
        </div>
        <h1 id="announcement-title" className="text-2xl leading-tight font-bold break-words text-foreground">
          {announcement.title}
        </h1>
      </header>

      {banner}

      {notice && (
        <div
          role="status"
          className={`flex items-start gap-2 rounded-lg px-3.5 py-3 text-sm ${notice.tone === "success" ? "bg-success-soft text-success" : "bg-surface-2 text-foreground"}`}
          data-announcement-notice={notice.tone}
        >
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span className="flex-1">{notice.message}</span>
          <button type="button" onClick={() => setNotice(null)} aria-label="إغلاق التنبيه" className="rounded p-0.5 hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-ring">
            <X className="size-4" aria-hidden />
          </button>
        </div>
      )}

      {active && (
        <Alert data-announcement-publishing={polling ? "polling" : "stopped"}>
          {polling ? <Loader2 className="size-4 animate-spin" /> : <Info className="size-4" />}
          <AlertTitle>{announcement.status === "QUEUED" ? "في انتظار النشر" : "قيد النشر"}</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-2">
            {polling
              ? "لم يصل الإعلان إلى الأسر بعد. تتحدث هذه الصفحة تلقائيًا حتى يكتمل النشر أو يتعذّر."
              : "لم يصل الإعلان إلى الأسر بعد، وتوقف التحديث التلقائي. حدّث الحالة يدويًا."}
            {!polling && (
              <Button size="sm" variant="outline" className="gap-1.5" onClick={() => query.refetch()} disabled={query.isFetching} data-announcement-refresh>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                تحديث الحالة
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}

      {announcement.status === "PUBLISH_FAILED" && failure && (
        <Alert variant="destructive" data-announcement-failure={announcement.publish_failure_code ?? ""}>
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر النشر</AlertTitle>
          <AlertDescription>{failure}</AlertDescription>
        </Alert>
      )}

      {actions.length > 0 && (
        <Section title="الإجراءات" field="actions">
          <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            {actions.map((action) => (
              <AnnouncementActionDialog key={action} side={side} id={announcement.id} action={action} copy={ANNOUNCEMENT_ACTION_COPY[action]} onDone={(n) => setNotice(n)} />
            ))}
          </div>
        </Section>
      )}

      <Section title="نص الإعلان" field="body">
        <p dir="auto" className="text-sm leading-relaxed break-words whitespace-pre-line text-foreground" data-announcement-body>
          {announcement.body}
        </p>
      </Section>

      <Section title="المستلمون" description="أعداد فقط؛ لا تُعرض الأسر بأعيانها." field="audience">
        <Selection announcement={announcement} />
      </Section>

      <Section title="مراحل الإعلان" field="lifecycle">
        <DetailList>
          <DetailItem label="أُنشئ">{when(announcement.created_at)}</DetailItem>
          <DetailItem label="طُلب النشر">{when(announcement.queued_at)}</DetailItem>
          <DetailItem label="نُشر">{when(announcement.published_at)}</DetailItem>
          <DetailItem label="ينتهي">{when(announcement.expires_at)}</DetailItem>
          <DetailItem label="أُرشف">{when(announcement.archived_at)}</DetailItem>
          <DetailItem label="أُلغي">{when(announcement.cancelled_at)}</DetailItem>
          <DetailItem label="محاولات النشر">{fmt(announcement.publish_attempts)}</DetailItem>
          <DetailItem label="آخر تعذّر للنشر">
            {announcement.publish_failure_code ? (
              <span className="flex flex-col items-end gap-0.5">
                <Code className="text-xs">{announcement.publish_failure_code}</Code>
                <span className="text-xs text-muted-foreground">{when(announcement.publish_failed_at)}</span>
              </span>
            ) : (
              "—"
            )}
          </DetailItem>
        </DetailList>
      </Section>

      {announcement.statistics_basis && (
        <Section title="الإحصاءات" field="statistics">
          <AnnouncementStatisticsPanel announcement={announcement} />
        </Section>
      )}

      {announcement.status === "DRAFT" && !actions.includes("PUBLISH") && (
        <p className="flex items-start gap-2 text-[13px] text-muted-foreground" data-announcement-no-send>
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          {noPublishNotice}
        </p>
      )}
    </article>
  );
}
