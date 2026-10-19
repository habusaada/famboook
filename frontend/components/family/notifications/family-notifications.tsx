"use client";

import { useEffect } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { AlertCircle, Archive, Bell, ChevronLeft, ChevronRight, Inbox, Megaphone, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { NotificationLevelBadge, NotificationStateBadges, NotificationTabs } from "@/components/family/notifications/notification-parts";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { isAccessFailure } from "@/lib/api/family-household";
import {
  type FamilyNotificationSummary,
  type FamilyNotificationView,
  normalizePage,
  useFamilyNotificationsQuery,
} from "@/lib/api/family-notifications";
import { formatTimestampDate } from "@/lib/utils/date";
import { RATE_LIMIT_MESSAGE, isRateLimited } from "@/lib/utils/family-notification";

const COPY: Record<FamilyNotificationView, { title: string; description: string; label: string; empty: string; emptyHint: string }> = {
  inbox: {
    title: "الإشعارات",
    description: "إشعارات أسرتك من إدارة Famboook ومنسق العائلة.",
    label: "الإشعارات الواردة",
    empty: "لا توجد إشعارات جديدة",
    emptyHint: "تظهر هنا الإشعارات الموجهة إلى أسرتك. الإشعارات المنتهية تجدها في الأرشيف.",
  },
  archive: {
    title: "أرشيف الإشعارات",
    description: "الإشعارات المنتهية والمؤرشفة. يمكنك فتحها وتأكيد الاطلاع عليها عند الحاجة.",
    label: "الإشعارات المؤرشفة",
    empty: "لا توجد إشعارات مؤرشفة",
    emptyHint: "تُنقل الإشعارات إلى الأرشيف عند انتهاء مدتها.",
  },
};

/**
 * One row: never the body, and opening it is a plain navigation — the list
 * records no view (the detail screen does). The unread state is text for
 * assistive technology, a dot visually.
 */
function NotificationCard({ notification }: { notification: FamilyNotificationSummary }) {
  const unread = notification.viewed_at === null;
  const Icon = notification.kind === "ANNOUNCEMENT" ? Megaphone : Bell;

  return (
    <li>
      <Link
        href={`/family/notifications/${encodeURIComponent(notification.id)}`}
        className="flex items-start gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        data-family-notification={notification.id}
        data-unread={unread ? "true" : undefined}
      >
        <span className="relative flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
          <Icon className="size-5" />
          {unread && <span className="absolute -end-0.5 -top-0.5 size-2.5 rounded-full bg-brand-700 ring-2 ring-surface-1" data-family-unread-dot />}
        </span>
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className={`break-words ${unread ? "font-semibold text-foreground" : "font-medium text-foreground"}`}>
            {unread && <span className="sr-only">جديد: </span>}
            {notification.title}
          </span>
          <span className="flex flex-wrap items-center gap-1.5">
            <NotificationLevelBadge level={notification.level} />
            <NotificationStateBadges notification={notification} />
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <span data-family-notification-source>{notification.source}</span>
            {notification.published_at && (
              <>
                <span aria-hidden>·</span>
                <span className="tabular-nums">{formatTimestampDate(notification.published_at)}</span>
              </>
            )}
          </span>
        </span>
        <ChevronLeft className="mt-2.5 size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

function Panel({ children, field }: { children: React.ReactNode; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-notifications-state={field}>
      {children}
    </section>
  );
}

/**
 * «الإشعارات» (FB-NOTIFY-01C.2): the household's active inbox or its archive,
 * one server page at a time (?page in the URL). The Family comes from the
 * session; a 401 / 403 is the session / access flow's, any other failure is
 * shown here with a retry.
 */
export function FamilyNotifications({ view }: { view: FamilyNotificationView }) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const rawPage = params.get("page");
  const page = normalizePage(rawPage);
  const query = useFamilyNotificationsQuery(view, page);
  const copy = COPY[view];

  const goTo = (next: number) => router.push(next > 1 ? `${pathname}?page=${next}` : pathname, { scroll: true });

  // A malformed ?page, or one the server refuses (422), falls back to page 1.
  const invalidPage = (rawPage !== null && String(page) !== rawPage) || (query.error instanceof ApiError && query.error.status === 422 && page > 1);
  useEffect(() => {
    if (invalidPage) router.replace(pathname);
  }, [invalidPage, pathname, router]);

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <div className="flex flex-col gap-5" data-family-notifications={view}>
      <FamilyRequestsHeader title={copy.title} description={copy.description} />
      <NotificationTabs current={view} />

      <div aria-busy={query.isPending} aria-live="polite">
        {query.data ? (
          rows.length === 0 ? (
            <Panel field="empty">
              {view === "archive" ? <Archive className="size-6 text-muted-foreground" aria-hidden /> : <Inbox className="size-6 text-muted-foreground" aria-hidden />}
              <p className="text-sm font-medium text-foreground">{copy.empty}</p>
              <p className="text-[13px] text-muted-foreground">{copy.emptyHint}</p>
              {page > 1 && (
                <Button variant="outline" className="h-10 rounded-xl" onClick={() => goTo(1)}>
                  الصفحة الأولى
                </Button>
              )}
            </Panel>
          ) : (
            <ul className="flex flex-col gap-3" aria-label={copy.label} data-family-notification-list>
              {rows.map((n) => (
                <NotificationCard key={n.id} notification={n} />
              ))}
            </ul>
          )
        ) : query.isError ? (
          !isAccessFailure(query.error) && (
            <Panel field={isRateLimited(query.error) ? "rate-limited" : "error"}>
              <AlertCircle className="size-6 text-danger" aria-hidden />
              <p className="text-sm font-medium text-foreground" role="alert">
                {isRateLimited(query.error) ? RATE_LIMIT_MESSAGE : "تعذّر تحميل الإشعارات"}
              </p>
              <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                إعادة المحاولة
              </Button>
            </Panel>
          )
        ) : (
          <div className="flex flex-col gap-3" data-family-notifications-loading>
            <p role="status" className="sr-only">
              جارٍ تحميل الإشعارات
            </p>
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-24 w-full rounded-2xl" />
            ))}
          </div>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <nav aria-label="التنقل بين الصفحات" className="flex items-center justify-between gap-2" data-family-notifications-pagination>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page <= 1} onClick={() => goTo(meta.current_page - 1)}>
            <ChevronRight className="size-4" aria-hidden />
            السابق
          </Button>
          <span className="text-[13px] text-muted-foreground" aria-current="page">
            صفحة <span className="tabular-nums">{meta.current_page.toLocaleString("ar")}</span> من{" "}
            <span className="tabular-nums">{meta.last_page.toLocaleString("ar")}</span>
          </span>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page >= meta.last_page} onClick={() => goTo(meta.current_page + 1)}>
            التالي
            <ChevronLeft className="size-4" aria-hidden />
          </Button>
        </nav>
      )}
    </div>
  );
}
