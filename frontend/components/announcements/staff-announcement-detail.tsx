"use client";

import { useAuth } from "@/components/auth/auth-context";
import { permittedActions } from "@/components/announcements/announcement-parts";
import { AnnouncementDetailView } from "@/components/announcements/announcement-detail-view";

/**
 * One of the Staff account's own announcements (FB-NOTIFY-01C.3c). Actions:
 * the state's available_actions AND the Staff permissions
 * (announcement.send for publish / retry; announcement.create — plus .send
 * once queued — for cancel). The server still decides.
 */
export function StaffAnnouncementDetail({ id }: { id: string }) {
  const { can } = useAuth();

  return (
    <AnnouncementDetailView
      side="staff"
      id={id}
      listHref="/announcements"
      listLabel="الإعلانات"
      actionsFor={(announcement) => permittedActions(announcement, { create: can("announcement.create"), send: can("announcement.send") })}
      noPublishNotice="لا تملك صلاحية طلب نشر الإعلانات."
    />
  );
}
