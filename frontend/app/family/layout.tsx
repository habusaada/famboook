import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Famboook — بوابة الأسرة",
  description: "بوابة الأسرة في فامبوك",
};

// The Family Portal (docs/11 §24–25). Independent of the Staff route group:
// no Staff AuthGate, no Staff shell, no /api/v1/me. `data-portal` re-points
// the design tokens to the Family palette for everything below it.

export default function FamilyLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <div data-portal="family" className="min-h-svh bg-canvas">
      {children}
    </div>
  );
}
