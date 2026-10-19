import Link from "next/link";
import { StatusBadge } from "@/components/shared/status-badge";
import type { FamilyNotificationSummary, FamilyNotificationView } from "@/lib/api/family-notifications";

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
