import { Suspense } from "react";
import { Skeleton } from "@/components/ui/skeleton";
import { ReportsPage } from "@/components/reports/reports-page";

export default function Page() {
  // useSearchParams (URL filter state) needs a Suspense boundary.
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full rounded-xl" />}>
      <ReportsPage />
    </Suspense>
  );
}
