import { CoordinatorGate } from "@/components/family/coordinator/coordinator-gate";

// Coordinator Space (docs/11 §8): inside the Family Portal, behind the
// FamilyGate of (portal), and opened only by the server's coordinator
// context (the CURRENT scope). No separate login.
//
// FB-NOTIFY-01C.3b: every scope-dependent Coordinator page lives in this
// (space) route group — the group does not change any URL. Routes placed
// beside it under coordinator/ (outside the group) are NOT behind this gate;
// only role-only history pages may live there, behind CoordinatorRoleGate.

export default function CoordinatorSpaceLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <CoordinatorGate>{children}</CoordinatorGate>;
}
