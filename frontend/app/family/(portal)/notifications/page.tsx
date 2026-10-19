import { Suspense } from "react";
import { FamilyNotifications } from "@/components/family/notifications/family-notifications";

export default function FamilyNotificationsPage() {
  // useSearchParams (the page in the URL) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <FamilyNotifications view="inbox" />
    </Suspense>
  );
}
