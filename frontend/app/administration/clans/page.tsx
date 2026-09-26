"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { ClanStructureAdmin } from "@/components/administration/clan-structure-admin";

export default function ClanStructurePage() {
  // Structure management only (the read-only structure appears in selectors).
  return (
    <RequirePermission permission="clan.manage">
      <ClanStructureAdmin />
    </RequirePermission>
  );
}
