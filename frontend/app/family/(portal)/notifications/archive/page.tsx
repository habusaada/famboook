import { Suspense } from "react";
import { FamilyNotifications } from "@/components/family/notifications/family-notifications";

export default function FamilyNotificationArchivePage() {
  // useSearchParams (the page in the URL) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <FamilyNotifications view="archive" />
    </Suspense>
  );
}
