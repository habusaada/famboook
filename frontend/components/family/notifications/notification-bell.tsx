"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Bell } from "lucide-react";
import { useFamilyUnreadNotificationsQuery } from "@/lib/api/family-notifications";
import { bellLabel, unreadBadgeText } from "@/lib/utils/family-notification";

/**
 * The header entry to the household's notifications (docs/11 §23 item 1:
 * header / unread notifications). The count is the inbox's server-side
 * meta.unread_count: no badge while loading or after a failure — never a
 * misleading zero — and none when nothing is unread. The shell renders it
 * only in the household context (never in Coordinator Space or without a
 * Family context); the bottom navigation is unchanged.
 */
export function NotificationBell() {
  const pathname = usePathname();
  const unread = useFamilyUnreadNotificationsQuery();
  const count = unread.data;
  const current = pathname === "/family/notifications" || pathname.startsWith("/family/notifications/");

  return (
    <Link
      href="/family/notifications"
      aria-label={bellLabel(count)}
      aria-current={current ? "page" : undefined}
      className={`relative flex size-10 items-center justify-center rounded-xl transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring ${
        current ? "text-brand-700" : "text-muted-foreground hover:text-foreground"
      }`}
      data-family-notification-bell
    >
      <Bell className="size-5" aria-hidden />
      {count !== undefined && count > 0 && (
        <span
          aria-hidden
          className="absolute -end-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1 text-[11px] leading-none font-semibold text-white tabular-nums"
          data-family-unread-badge
        >
          {unreadBadgeText(count)}
        </span>
      )}
    </Link>
  );
}
