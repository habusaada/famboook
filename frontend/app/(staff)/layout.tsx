import { Suspense } from "react";
import { AuthGate } from "@/components/auth/auth-gate";

// The Staff Portal (docs/11 §25): every route in this group is behind the
// Staff AuthGate and shell. The group does not change any URL. Family Portal
// routes (/family) live outside it and never mount or query the Staff gate.

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
