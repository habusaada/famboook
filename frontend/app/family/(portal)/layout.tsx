import { FamilyGate } from "@/components/family/family-gate";

// The authenticated part of the Family Portal: behind FamilyGate and inside
// the Family shell. /family/login, /family/activate and
// /family/forgot-password are outside this group and stay public.

export default function FamilyPortalLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <FamilyGate>{children}</FamilyGate>;
}
