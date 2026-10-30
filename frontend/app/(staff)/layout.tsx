import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthGate } from "@/components/auth/auth-gate";

// The Staff Portal (docs/11 §25): every route in this group is behind the
// Staff AuthGate and shell. The group does not change any URL. Family Portal
// routes (/family) live outside it and never mount or query the Staff gate.

// FB-WEB-04: the homepage is public, so this group opts out of indexing itself.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default function StaffLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    // useSearchParams (login redirect target) needs a Suspense boundary.
    <Suspense fallback={null}>
      <AuthGate>{children}</AuthGate>
    </Suspense>
  );
}
