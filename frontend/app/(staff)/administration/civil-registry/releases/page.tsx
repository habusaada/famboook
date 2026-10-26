"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { ReleaseHistory } from "@/components/administration/civil-registry/releases";

export default function CivilImportReleasesPage() {
  return (
    <RequirePermission permission="civil-import.view">
      <ReleaseHistory />
    </RequirePermission>
  );
}
