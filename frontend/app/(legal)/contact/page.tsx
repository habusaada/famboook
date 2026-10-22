import type { Metadata } from "next";
import { LegalPageView } from "@/components/legal/legal-page-view";
import { legalMetadata, legalPage } from "@/lib/legal/pages";

// FB-LEGAL-01B: content comes only from the registry (lib/legal/pages.ts).
// While the page awaits PCDCD approval it shows a neutral notice — no
// policy text, version, effective date or acceptance control.

export const metadata: Metadata = legalMetadata("contact");

export default function ContactPage() {
  return <LegalPageView page={legalPage("contact")} />;
}
