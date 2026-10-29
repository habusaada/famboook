"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { CivilLookupAccess } from "@/components/administration/civil-registry/lookup-access";

export default function CivilLookupAccessPage() {
  return (
    <RequirePermission permission="civil-lookup.manage">
      <CivilLookupAccess />
    </RequirePermission>
  );
}
