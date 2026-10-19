"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, CheckCircle2, ExternalLink, Info, RotateCw, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AcknowledgeDialog, type NotificationNotice } from "@/components/family/notifications/acknowledge-dialog";
import { NotificationLevelBadge, NotificationStateBadges } from "@/components/family/notifications/notification-parts";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { isAccessFailure } from "@/lib/api/family-household";
import { type FamilyNotification, useFamilyNotificationMutation, useFamilyNotificationQuery } from "@/lib/api/family-notifications";
import { formatDateTime } from "@/lib/utils/date";
import { RATE_LIMIT_MESSAGE, isRateLimited, safeActionPath } from "@/lib/utils/family-notification";

const INBOX = { href: "/family/notifications", label: "الإشعارات" };
const ARCHIVE = { href: "/family/notifications/archive", label: "أرشيف الإشعارات" };

function Section({ title, children, field }: { title: string; children: React.ReactNode; field: string }) {
  const id = `family-notification-${field}`;
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-labelledby={id} data-family-notification-section={field}>
      <h2 id={id} className="mb-3 text-base font-semibold text-foreground">
        {title}
      </h2>
      {children}
    </section>
  );
}

function Loading() {
  return (
    <div className="flex flex-col gap-4" data-family-notification-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل الإشعار
      </p>
      <Skeleton className="h-8 w-48 rounded-lg" />
      <Skeleton className="h-24 w-full rounded-2xl" />
      <Skeleton className="h-40 w-full rounded-2xl" />
    </div>
  );
}

/**
 * Records the household's view ONCE per mount, after an eligible detail has
 * rendered (available_actions has VIEW): a ref guards StrictMode's double
 * effect and re-renders; the server is idempotent anyway (a repeat is a
 * replay). Lists and hovers never get here, and a route prefetch loads
 * code only — it never runs this effect.
 */
function useRecordView(id: string, notification: FamilyNotification | undefined) {
  const { mutate } = useFamilyNotificationMutation(id, "view");
  const recorded = useRef<string | null>(null);
  const eligible = notification !== undefined && notification.id === id && notification.available_actions.includes("VIEW");

  useEffect(() => {
    if (!eligible || recorded.current === id) return;
    recorded.current = id;
    mutate();
  }, [eligible, id, mutate]);
}

function BackLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
    >
      <ArrowRight className="size-4" aria-hidden />
      {label}
    </Link>
  );
}

function Acknowledgement({ notification, onDone }: { notification: FamilyNotification; onDone: (n: NotificationNotice) => void }) {
  if (!notification.requires_acknowledgement) return null;

  if (notification.acknowledged_at) {
    return (
      <Section title="تأكيد الاطلاع" field="acknowledgement">
        <p className="flex items-start gap-2 text-sm text-foreground" data-family-notification-acknowledged>
          <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
          <span>
            أكّدت الاطلاع على هذا الإشعار في <span className="tabular-nums">{formatDateTime(notification.acknowledged_at)}</span>
            {notification.acknowledged_late && <span data-family-notification-late> (بعد انتهاء مدة الإشعار)</span>}.
          </span>
        </p>
      </Section>
    );
  }

  if (!notification.available_actions.includes("ACKNOWLEDGE")) return null;

  return (
    <Section title="تأكيد الاطلاع" field="acknowledgement">
      <p className="mb-3 text-sm text-foreground">
        هذا إشعار مهم ويتطلب تأكيد اطلاعك عليه. فتح الإشعار وحده لا يُعدّ تأكيدًا.
        {notification.expired && " انتهت مدة الإشعار، وسيُسجَّل التأكيد متأخرًا."}
      </p>
      <AcknowledgeDialog id={notification.id} onDone={onDone} />
    </Section>
  );
}

/**
 * One notification of the household (FB-NOTIFY-01C.2): read with GET first
 * (which records nothing), then the view is recorded explicitly. The body is
 * plain text — React escapes it; line breaks are kept by CSS, never by
 * markup. A link is offered only for a validated family-relative
 * action_path.
 */
export function FamilyNotificationDetail({ id }: { id: string }) {
  const query = useFamilyNotificationQuery(id);
  const [notice, setNotice] = useState<NotificationNotice | null>(null);
  useRecordView(id, query.data);

  if (!query.data) {
    if (query.isError) {
      if (isAccessFailure(query.error)) return null;
      const missing = query.error instanceof ApiError && query.error.status === 404;
      const limited = isRateLimited(query.error);
      return (
        <div className="flex flex-col gap-5">
          <FamilyRequestsHeader back={INBOX} title={missing ? "الإشعار غير متاح" : "تعذّر تحميل الإشعار"} />
          <section
            className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center"
            data-family-notification-error={missing ? "missing" : limited ? "rate-limited" : "failed"}
          >
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-foreground" role="alert">
              {missing ? "لم نجد هذا الإشعار ضمن إشعارات أسرتك." : limited ? RATE_LIMIT_MESSAGE : "حدث خطأ أثناء تحميل الإشعار."}
            </p>
            {missing ? (
              <Button asChild variant="outline" className="h-10 rounded-xl">
                <Link href={INBOX.href}>
                  العودة إلى الإشعارات
                </Link>
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

  const notification = query.data;
  const actionPath = safeActionPath(notification.action_path);

  return (
    <article className="flex flex-col gap-4" data-family-notification-detail={notification.id} aria-labelledby="family-notification-title">
      <header>
        <BackLink {...(notification.expired ? ARCHIVE : INBOX)} />
        <h1 id="family-notification-title" className="mt-1 text-2xl leading-snug font-bold break-words text-foreground">
          {notification.title}
        </h1>
      </header>

      {notice && (
        <div
          role="status"
          className={`flex items-start gap-2 rounded-xl px-3.5 py-3 text-sm ${notice.tone === "success" ? "bg-success-soft text-success" : "bg-surface-2 text-foreground"}`}
          data-family-notification-notice={notice.tone}
        >
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span className="flex-1">{notice.message}</span>
          <button type="button" onClick={() => setNotice(null)} aria-label="إغلاق التنبيه" className="rounded p-0.5 hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-ring">
            <X className="size-4" aria-hidden />
          </button>
        </div>
      )}

      <Section title="تفاصيل الإشعار" field="meta">
        <div className="flex flex-wrap items-center gap-2">
          <NotificationLevelBadge level={notification.level} />
          <NotificationStateBadges notification={notification} />
          <bdi dir="ltr" className="font-mono text-xs tracking-wide text-muted-foreground" data-family-notification-code>
            {notification.notice_code}
          </bdi>
        </div>
        <dl className="mt-3 grid gap-2 text-sm">
          <div className="flex flex-wrap gap-x-2">
            <dt className="text-muted-foreground">المصدر:</dt>
            <dd className="text-foreground" data-family-notification-source>
              {notification.source}
            </dd>
          </div>
          {notification.published_at && (
            <div className="flex flex-wrap gap-x-2">
              <dt className="text-muted-foreground">تاريخ النشر:</dt>
              <dd className="text-foreground tabular-nums">{formatDateTime(notification.published_at)}</dd>
            </div>
          )}
          {notification.expires_at && (
            <div className="flex flex-wrap gap-x-2">
              <dt className="text-muted-foreground">{notification.expired ? "انتهى في:" : "ينتهي في:"}</dt>
              <dd className="text-foreground tabular-nums">{formatDateTime(notification.expires_at)}</dd>
            </div>
          )}
        </dl>
        {notification.expired && (
          <p className="mt-3 flex items-start gap-2 rounded-xl bg-surface-2 px-3.5 py-3 text-[13px] text-muted-foreground" data-family-notification-expired-note>
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            انتهت مدة هذا الإشعار ونُقل إلى الأرشيف. يبقى متاحًا للاطلاع.
          </p>
        )}
      </Section>

      <Section title="نص الإشعار" field="body">
        <p dir="auto" className="text-sm leading-relaxed break-words whitespace-pre-line text-foreground" data-family-notification-body>
          {notification.body}
        </p>
        {actionPath && (
          <Button asChild variant="outline" className="mt-4 h-10 gap-2 rounded-xl">
            <Link href={actionPath} data-family-notification-link>
              <ExternalLink className="size-4" aria-hidden />
              عرض التفاصيل المرتبطة
            </Link>
          </Button>
        )}
      </Section>

      <Acknowledgement notification={notification} onDone={setNotice} />
    </article>
  );
}
