"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { AuditHistory } from "@/components/administration/civil-registry/audit-history";

export default function CivilImportAuditPage() {
  return (
    <RequirePermission permission="civil-import.audit.view">
      <AuditHistory />
    </RequirePermission>
  );
}
