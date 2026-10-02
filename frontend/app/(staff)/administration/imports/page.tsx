"use client";

import { Suspense } from "react";
import { useAuth } from "@/components/auth/auth-context";
import { RequirePermission } from "@/components/auth/require-permission";
import { ImportWizard } from "@/components/administration/import-wizard/import-wizard";

export default function ImportWizardPage() {
  const { can } = useAuth();

  // Upload (import.upload) or review (import.review); the API authorizes every call.
  return (
    <RequirePermission permission={can("import.upload") ? "import.upload" : "import.review"}>
      {/* useSearchParams (?batch=) needs a Suspense boundary. */}
      <Suspense fallback={null}>
        <ImportWizard />
      </Suspense>
    </RequirePermission>
  );
}
