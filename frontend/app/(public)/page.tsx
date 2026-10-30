import type { Metadata } from "next";
import { LandingPage } from "@/components/landing/landing-page";

// FB-WEB-04 — the public homepage (docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md). A route
// group outside (staff) and family/: no AuthGate, no FamilyGate, no manifest and
// no service worker. /welcome redirects here permanently (next.config.ts).

const TITLE = "فامبوك | منصة رقمية لتنظيم العائلات والعشائر";
const DESCRIPTION = "منصة رقمية متخصصة تمكّن مجالس العائلات والعشائر من تنظيم بيانات فروعها وأسرها وأفرادها، وإدارة الهياكل العائلية، وتعزيز التنسيق والتواصل المجتمعي.";
const HOME_URL = "https://famboook.com/";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: HOME_URL },
  openGraph: { type: "website", url: HOME_URL, siteName: "فامبوك", locale: "ar", title: TITLE, description: DESCRIPTION },
};

export default function HomePage() {
  return <LandingPage />;
}
