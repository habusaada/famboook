"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { NewImportWizard } from "@/components/administration/civil-registry/new-import-wizard";

export default function NewCivilImportPage() {
  // UX gate only: the API authorizes every request (civil-import.stage).
  return (
    <RequirePermission permission="civil-import.stage">
      <NewImportWizard />
    </RequirePermission>
  );
}
