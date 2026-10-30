import type { Metadata } from "next";
import { LandingPage } from "@/components/landing/landing-page";

// FB-WEB-02B — the public landing page (docs/adr/FB-WEB-02-LANDING-PAGE.md).
// A route group outside (staff) and family/: no AuthGate, no FamilyGate, no
// manifest and no service worker. It does not replace `/`. Not indexed until
// the owner approves its publication.

export const metadata: Metadata = {
  title: "فامبوك | Famboook — منصة رقمية لتنظيم العائلات والعشائر",
  description: "منصة رقمية متخصصة تمكّن مجالس العائلات والعشائر من تنظيم بيانات فروعها وأسرها وأفرادها، وإدارة الهياكل العائلية، وتعزيز التنسيق والتواصل المجتمعي.",
  robots: { index: false, follow: false },
};

export default function WelcomePage() {
  return <LandingPage />;
}
