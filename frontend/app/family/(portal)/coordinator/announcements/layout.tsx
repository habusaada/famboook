import { CoordinatorRoleGate } from "@/components/family/coordinator/coordinator-role-gate";

// FB-NOTIFY-01C.3d: the coordinator's OWN announcement history and details —
// role-only (CoordinatorRoleGate: the COORDINATOR role as /family/me reports
// it), deliberately OUTSIDE the (space) group: they stay readable after the
// scope is revoked, as the backend serves them outside coordinator.space.
// Composing needs the current scope: /announcements/new lives in
// (space)/announcements/new, behind CoordinatorGate.

export default function CoordinatorAnnouncementsLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <CoordinatorRoleGate>{children}</CoordinatorRoleGate>;
}
