"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { ImportHistory } from "@/components/administration/civil-registry/import-history";

export default function CivilImportHistoryPage() {
  return (
    <RequirePermission permission="civil-import.view">
      <ImportHistory />
    </RequirePermission>
  );
}
