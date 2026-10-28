"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { CivilLookupPage } from "@/components/civil-lookup/civil-lookup-page";

export default function CivilRegistryLookupPage() {
  return (
    <RequirePermission permission="civil-lookup.search">
      <CivilLookupPage />
    </RequirePermission>
  );
}
