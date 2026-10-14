import { Suspense } from "react";
import { CoordinatorRequests } from "@/components/family/coordinator/requests/coordinator-requests";

export default function CoordinatorRequestsPage() {
  // useSearchParams (URL filter state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <CoordinatorRequests />
    </Suspense>
  );
}
