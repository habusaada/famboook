"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { StaffAnnouncementCompose } from "@/components/announcements/staff-announcement-compose";

export default function NewAnnouncementPage() {
  // The section needs announcement.view (AuthGate); drafting needs announcement.create.
  return (
    <RequirePermission permission="announcement.create">
      <StaffAnnouncementCompose />
    </RequirePermission>
  );
}
