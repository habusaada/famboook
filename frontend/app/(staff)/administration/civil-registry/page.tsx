"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { CivilRegistryOverview } from "@/components/administration/civil-registry/civil-registry-overview";

export default function CivilRegistryPage() {
  // UX gate only: every civil-registry endpoint authorizes on the server.
  return (
    <RequirePermission permission="civil-import.view">
      <CivilRegistryOverview />
    </RequirePermission>
  );
}
