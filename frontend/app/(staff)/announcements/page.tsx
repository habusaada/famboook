import { Suspense } from "react";
import { StaffAnnouncementList } from "@/components/announcements/staff-announcement-list";

export default function AnnouncementsPage() {
  // useSearchParams (URL filter and page state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <StaffAnnouncementList />
    </Suspense>
  );
}
