import { Suspense } from "react";
import { CoordinatorAnnouncementList } from "@/components/family/coordinator/announcements/coordinator-announcement-list";

export default function CoordinatorAnnouncementsPage() {
  // useSearchParams (URL filter and page state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <CoordinatorAnnouncementList />
    </Suspense>
  );
}
