import { Suspense } from "react";
import { StaffFollowupList } from "@/components/followups/staff-followups";

export default function FollowupsPage() {
  // useSearchParams (URL filter and page state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <StaffFollowupList />
    </Suspense>
  );
}
