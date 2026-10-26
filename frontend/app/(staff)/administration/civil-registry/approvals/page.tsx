"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { PublicationQueue } from "@/components/administration/civil-registry/publications";

export default function CivilImportApprovalsPage() {
  // UX gate only: the API authorizes every read and decision.
  return (
    <RequirePermission permission="civil-import.view">
      <PublicationQueue />
    </RequirePermission>
  );
}
