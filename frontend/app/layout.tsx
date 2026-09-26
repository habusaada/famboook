import { Suspense } from "react";
import type { Metadata } from "next";
import { IBM_Plex_Sans_Arabic } from "next/font/google";
import { AuthGate } from "@/components/auth/auth-gate";
import { QueryProvider } from "@/components/providers/query-provider";
import "./globals.css";

const ibmPlexSansArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic", "latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-sans",
});

export const metadata: Metadata = {
  title: "Famboook",
  description: "نظام سجل العائلات وإدارة الحالات",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ar" dir="rtl" className={ibmPlexSansArabic.variable}>
      <body className="font-sans antialiased">
        <QueryProvider>
          {/* useSearchParams (login redirect target) needs a Suspense boundary. */}
          <Suspense fallback={null}>
            <AuthGate>{children}</AuthGate>
          </Suspense>
        </QueryProvider>
      </body>
    </html>
  );
}
