"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { CivilLookupQuotas } from "@/components/administration/civil-registry/lookup-quotas";

export default function CivilLookupQuotasPage() {
  return (
    <RequirePermission permission="civil-lookup.usage.view">
      <CivilLookupQuotas />
    </RequirePermission>
  );
}
