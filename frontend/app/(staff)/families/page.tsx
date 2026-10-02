import { Suspense } from "react";
import { FamiliesRegistry } from "@/components/families/families-registry";

export default function FamiliesPage() {
  // useSearchParams (URL search state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <FamiliesRegistry />
    </Suspense>
  );
}
