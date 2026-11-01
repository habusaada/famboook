import type { Metadata } from "next";
import { LandingPage } from "@/components/landing/landing-page";
import { WhatsAppWidget } from "@/components/shared/whatsapp-widget";

// FB-WEB-04 — the public homepage (docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md). A route
// group outside (staff) and family/: no AuthGate, no FamilyGate, no manifest and
// no service worker. /welcome redirects here permanently (next.config.ts).

const TITLE = "فامبوك | منصة رقمية لتنظيم العائلات والعشائر";
const DESCRIPTION = "منصة رقمية متخصصة تمكّن مجالس العائلات والعشائر من تنظيم بيانات فروعها وأسرها وأفرادها، وإدارة الهياكل العائلية، وتعزيز التنسيق والتواصل المجتمعي.";
const HOME_URL = "https://famboook.com/";
// FB-SEO-02: the approved sharing image (public/brand/famboook-og.png, 1200 × 630).
const SHARE_IMAGE = "https://famboook.com/brand/famboook-og.png";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: HOME_URL },
  openGraph: {
    type: "website",
    url: HOME_URL,
    siteName: "فامبوك",
    locale: "ar",
    title: TITLE,
    description: DESCRIPTION,
    images: [{ url: SHARE_IMAGE, width: 1200, height: 630, alt: "فامبوك - منصة رقمية لتنظيم العائلات والعشائر" }],
  },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION, images: [SHARE_IMAGE] },
};

// FB-WEB-05: the contact widget is on this page only (the legal pages stay FB-LEGAL-01D).
export default function HomePage() {
  return (
    <>
      <LandingPage />
      <WhatsAppWidget />
    </>
  );
}
