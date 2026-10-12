"use client";

import { RequirePermission } from "@/components/auth/require-permission";
import { CoordinatorAdmin } from "@/components/administration/coordinators/coordinator-admin";

export default function CoordinatorsPage() {
  // UX gate only: the coordinator routes and Domain Actions are the authority.
  return (
    <RequirePermission permission="coordinator-scope.manage">
      <CoordinatorAdmin />
    </RequirePermission>
  );
}
