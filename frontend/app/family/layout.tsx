import type { Metadata, Viewport } from "next";

// The installable Family app: its manifest, the official Famboook icon
// (public/icons, rendered from famboook-icon.svg) and the Apple home-screen
// metadata. Linked from the Family Portal only — the Staff application is
// not an installable app.
export const metadata: Metadata = {
  title: "Famboook — بوابة الأسرة",
  description: "بوابة الأسرة في فامبوك",
  manifest: "/manifest.webmanifest",
  icons: {
    icon: [
      { url: "/icons/famboook-icon.svg", type: "image/svg+xml" },
      { url: "/icons/icon-192.png", sizes: "192x192", type: "image/png" },
    ],
    apple: [{ url: "/icons/apple-touch-icon.png", sizes: "180x180", type: "image/png" }],
  },
  appleWebApp: { capable: true, title: "فامبوك", statusBarStyle: "default" },
};

export const viewport: Viewport = {
  themeColor: "#751BD5",
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
