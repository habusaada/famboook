import { Suspense } from "react";
import { ChangeRequestsQueue } from "@/components/change-requests/change-requests-queue";

export default function ChangeRequestsPage() {
  // useSearchParams (URL filter state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <ChangeRequestsQueue />
    </Suspense>
  );
}
