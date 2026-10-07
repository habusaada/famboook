import type { Metadata } from "next";
import { CredentialVerification } from "@/components/verify/credential-verification";

// Public Digital Family Card verification (docs/11 §19, FP-ADR-070): the QR
// target. Outside /family (no Family gate, outside the service worker's
// scope) and outside the Staff group. Not indexed, no referrer; the
// response headers (no-store, noindex, no-referrer) are set in
// next.config.ts. The token is handed to the client component, which posts
// it to the API in the request body; it is never logged here.

export const metadata: Metadata = {
  title: "التحقق من بطاقة الأسرة الرقمية — Famboook",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default async function VerifyCredentialPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;

  return <CredentialVerification token={token} />;
}
