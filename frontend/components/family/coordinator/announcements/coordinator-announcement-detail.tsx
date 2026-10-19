"use client";

import { AnnouncementDetailView } from "@/components/announcements/announcement-detail-view";
import { permittedActions } from "@/components/announcements/announcement-parts";
import { NoCurrentScopeNotice } from "@/components/family/coordinator/announcements/coordinator-announcement-parts";
import { useFamilyUser } from "@/components/family/family-context";
import type { AnnouncementAction, AnnouncementDetail } from "@/lib/api/announcements";

/**
 * The coordinator's actions on one of their own announcements: the state's
 * available_actions, the coordinator-announcement permissions (held through
 * the COORDINATOR role — /family/me `coordinator`), and for publish / retry
 * the CURRENT scope (`coordinator_space`). Cancel needs no current scope (the
 * backend serves it outside coordinator.space). The server still decides.
 */
export function coordinatorActions(
  announcement: Pick<AnnouncementDetail, "available_actions" | "status">,
  user: { coordinator: boolean; coordinator_space: boolean }
): AnnouncementAction[] {
  return permittedActions(announcement, { create: user.coordinator, send: user.coordinator }).filter(
    (action) => action === "CANCEL" || user.coordinator_space
  );
}

/**
 * One of the coordinator's own announcements (FB-NOTIFY-01C.3d). Role-only:
 * it stays readable after the scope is revoked. Statistics are the server's
 * — counted over the CURRENT scope only, and none at all (NO_CURRENT_SCOPE)
 * without one; the publication-time recipient count stays the historical
 * fact.
 */
export function CoordinatorAnnouncementDetail({ id }: { id: string }) {
  const user = useFamilyUser();

  return (
    <AnnouncementDetailView
      side="coordinator"
      id={id}
      listHref="/family/coordinator/announcements"
      listLabel="إعلاناتي"
      actionsFor={(announcement) => coordinatorActions(announcement, user)}
      noPublishNotice="لا يمكن طلب النشر دون نطاق تنسيق حالي. يمكنك إلغاء المسودة."
      banner={user.coordinator_space ? null : <NoCurrentScopeNotice />}
    />
  );
}
