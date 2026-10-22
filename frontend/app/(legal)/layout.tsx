import { FamilyBrand } from "@/components/family/family-brand";
import { SiteFooter } from "@/components/shared/site-footer";

// FB-LEGAL-01B: the public legal and information pages (/about, /privacy,
// /terms, /data-protection, /contact). A route group OUTSIDE (staff) and
// family/: no AuthGate, no FamilyGate, no Family service-worker scope — no
// session is ever needed. Server-rendered, no client state; RTL from the root
// layout. The narrow public column of the credential verification page.

export default function LegalLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <main className="mx-auto flex min-h-svh w-full max-w-2xl flex-col gap-6 px-4 py-8 sm:px-6" data-legal-layout>
      <header className="flex justify-center">
        <FamilyBrand size="lg" />
      </header>
      {children}
      <SiteFooter className="mt-auto pt-4" />
    </main>
  );
}
