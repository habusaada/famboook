import { Suspense } from "react";
import { FamilyRequests } from "@/components/family/requests/family-requests";

export default function FamilyRequestsPage() {
  // useSearchParams (URL filter state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <FamilyRequests />
    </Suspense>
  );
}
