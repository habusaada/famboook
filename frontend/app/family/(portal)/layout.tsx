import { FamilyGate } from "@/components/family/family-gate";

// The authenticated part of the Family Portal: behind FamilyGate and inside
// the Family shell. /family/activate is outside this group and stays public.

export default function FamilyPortalLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <FamilyGate>{children}</FamilyGate>;
}
