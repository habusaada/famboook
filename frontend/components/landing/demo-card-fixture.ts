import type { FamilyCard } from "@/lib/api/family-card";

// FB-WEB-02D-CARD — the only data behind the card on the homepage. Invented and
// fixed. It holds no token, no verification URL and no QR, and its number cannot
// be a real one: «DEMO-…» does not match CredentialNumbers::FORMAT (FC-XXXX-XXXX-XX).

export const DEMO_CARD_LABEL = "نموذج توضيحي — بيانات غير حقيقية";

export const demoFamilyCard: FamilyCard = {
  credential_number: "DEMO-0000-00",
  family_code: "DEMO-001",
  issued_at: "2026-01-01",
  clan: "عشيرة تجريبية",
  branch: "فرع تجريبي (أ)",
  head_name: "رب أسرة افتراضي",
  verification_url: null,
  qr: null,
  qr_available: false,
};
