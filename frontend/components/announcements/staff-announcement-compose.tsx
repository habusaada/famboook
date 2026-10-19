"use client";

import { useAuth } from "@/components/auth/auth-context";
import { type AudienceOption, AnnouncementComposeForm } from "@/components/announcements/announcement-compose-form";
import { StaffFamilyCodePicker } from "@/components/announcements/family-code-picker";
import { STAFF_AUDIENCES } from "@/lib/schemas/announcement";

const SELECTED: AudienceOption = { type: "STAFF_FAMILIES", label: "أسر محددة" };
const ALL_ACTIVE: AudienceOption = { type: "STAFF_ALL_ACTIVE", label: "جميع الأسر النشطة", description: "يُرسل الإعلان إلى جميع الأسر النشطة عند النشر." };

/**
 * A new Staff announcement DRAFT (FB-NOTIFY-01C.3c): selected families by
 * PUBLIC family code (the registry), or every ACTIVE family — offered only
 * with announcement.send-all (checked again by the server).
 */
export function StaffAnnouncementCompose() {
  const { can } = useAuth();
  const options = can("announcement.send-all") ? [SELECTED, ALL_ACTIVE] : [SELECTED];

  return (
    <AnnouncementComposeForm
      side="staff"
      grammar={STAFF_AUDIENCES}
      options={options}
      Picker={StaffFamilyCodePicker}
      listHref="/announcements"
      listLabel="الإعلانات"
      detailHref={(id) => `/announcements/${encodeURIComponent(id)}`}
    />
  );
}
