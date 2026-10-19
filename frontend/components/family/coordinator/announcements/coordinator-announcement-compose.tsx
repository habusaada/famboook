"use client";

import { type AudienceOption, AnnouncementComposeForm } from "@/components/announcements/announcement-compose-form";
import { CoordinatorFamilyCodePicker } from "@/components/announcements/family-code-picker";
import { COORDINATOR_AUDIENCES } from "@/lib/schemas/announcement";

const OPTIONS: AudienceOption[] = [
  {
    type: "COORDINATOR_SCOPE",
    label: "كامل نطاقي الحالي",
    description: "يُرسل الإعلان إلى جميع الأسر النشطة في نطاق التنسيق الحالي عند النشر.",
  },
  { type: "COORDINATOR_SELECTION", label: "أسر محددة من نطاقي" },
];

/**
 * A new coordinator announcement DRAFT (FB-NOTIFY-01C.3d). Rendered only
 * inside Coordinator Space (CoordinatorGate — the CURRENT scope); the server
 * re-checks the coordinator-announcement permission and the scope. Coordinator
 * audiences only: the whole current scope, or selected in-scope families by
 * PUBLIC family code from the Coordinator directory.
 */
export function CoordinatorAnnouncementCompose() {
  return (
    <AnnouncementComposeForm
      side="coordinator"
      grammar={COORDINATOR_AUDIENCES}
      options={OPTIONS}
      Picker={CoordinatorFamilyCodePicker}
      listHref="/family/coordinator/announcements"
      listLabel="إعلاناتي"
      detailHref={(id) => `/family/coordinator/announcements/${encodeURIComponent(id)}`}
    />
  );
}
