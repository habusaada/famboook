import { Suspense } from "react";
import { PeopleRegistry } from "@/components/people/people-registry";

export default function PeoplePage() {
  // useSearchParams (URL search state) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <PeopleRegistry />
    </Suspense>
  );
}
