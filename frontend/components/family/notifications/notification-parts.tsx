import Link from "next/link";
import { Bell, Megaphone } from "lucide-react";
import { StatusBadge } from "@/components/shared/status-badge";
import type { FamilyNotificationSummary, FamilyNotificationView } from "@/lib/api/family-notifications";
import { formatTimestampDate } from "@/lib/utils/date";

// Shared pieces of the Family notification screens (FB-NOTIFY-01C.2). Every
// state is text (a badge with a label), never color alone.

/** IMPORTANT only; a NORMAL notification carries no level badge. */
export function NotificationLevelBadge({ level }: { level: FamilyNotificationSummary["level"] }) {
  if (level !== "IMPORTANT") return null;
  return (
    <StatusBadge tone="warning">
      <span data-family-notification-level="IMPORTANT">مهم</span>
    </StatusBadge>
  );
}

/** The acknowledgement and expiry states the server reports. */
export function NotificationStateBadges({ notification }: { notification: FamilyNotificationSummary }) {
  const pending = notification.available_actions.includes("ACKNOWLEDGE");
  return (
    <>
      {pending && (
        <StatusBadge tone="brand">
          <span data-family-notification-state="pending-ack">بانتظار تأكيد الاطلاع</span>
        </StatusBadge>
      )}
      {notification.acknowledged_at && (
        <StatusBadge tone="success">
          <span data-family-notification-state="acknowledged">{notification.acknowledged_late ? "أُكّد الاطلاع متأخرًا" : "تم تأكيد الاطلاع"}</span>
        </StatusBadge>
      )}
      {notification.expired && (
        <StatusBadge tone="neutral">
          <span data-family-notification-state="expired">منتهي</span>
        </StatusBadge>
      )}
    </>
  );
}

/** The surface of one notification row. */
export const NOTIFICATION_ROW_CLASS = "flex items-start gap-3 rounded-2xl border border-border bg-surface-1 p-4";

/**
 * One row's icon, title, badges and context — never the body, and no
 * navigation: the caller decides what surrounds it (FB-WEB-02B). The unread
 * state is text for assistive technology, a dot visually.
 */
export function NotificationRowContent({ notification }: { notification: FamilyNotificationSummary }) {
  const unread = notification.viewed_at === null;
  const Icon = notification.kind === "ANNOUNCEMENT" ? Megaphone : Bell;

  return (
    <>
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
    </>
  );
}

/** «الوارد» | «الأرشيف»: real links, so each list has its own URL. */
export function NotificationTabs({ current }: { current: FamilyNotificationView }) {
  const tab = (view: FamilyNotificationView, href: string, label: string) => (
    <Link
      href={href}
      aria-current={current === view ? "page" : undefined}
      className={`flex min-h-10 flex-1 items-center justify-center rounded-lg px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring ${
        current === view ? "bg-surface-1 font-semibold text-brand-700 shadow-sm" : "font-medium text-muted-foreground hover:text-foreground"
      }`}
      data-family-notifications-tab={view}
    >
      {label}
    </Link>
  );

  return (
    <nav aria-label="قوائم الإشعارات" className="flex gap-1 rounded-xl bg-surface-2 p-1">
      {tab("inbox", "/family/notifications", "الوارد")}
      {tab("archive", "/family/notifications/archive", "الأرشيف")}
    </nav>
  );
}
