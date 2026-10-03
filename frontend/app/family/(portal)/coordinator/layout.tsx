import { CoordinatorGate } from "@/components/family/coordinator/coordinator-gate";

// Coordinator Space (docs/11 §8): inside the Family Portal, behind the
// FamilyGate of (portal), and opened only by the server's coordinator
// context. No separate login.

export default function CoordinatorLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <CoordinatorGate>{children}</CoordinatorGate>;
}
