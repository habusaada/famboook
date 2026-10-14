import { Suspense } from "react";
import { CoordinatorAddMemberForm } from "@/components/family/coordinator/requests/coordinator-add-member-form";

export default function CoordinatorAddMemberPage() {
  // ?family= (an optional pre-selection) is read with useSearchParams.
  return (
    <Suspense fallback={null}>
      <CoordinatorAddMemberForm />
    </Suspense>
  );
}
