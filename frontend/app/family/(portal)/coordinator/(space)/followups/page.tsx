import { Suspense } from "react";
import { CoordinatorFollowupList } from "@/components/family/coordinator/followups/coordinator-followups";

export default function CoordinatorFollowupsPage() {
  // useSearchParams (URL filter and page state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <CoordinatorFollowupList />
    </Suspense>
  );
}
