import type { FamilyCardRevokeReason, FamilyCardStatus } from "@/lib/api/family-cards";

// The Digital Family Card vocabulary (docs/11 FP-ADR-070). It is a digital
// verification credential inside Famboook — never «هوية».

export const FAMILY_CARD_TITLE = "بطاقة الأسرة الرقمية";

export const FAMILY_CARD_DISCLAIMER =
  "وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية. يُثبت رمز QR صلاحية البطاقة فقط، ولا يُثبت هوية الشخص الذي يحملها.";

/** The one public failure: never says why (malformed, unknown, revoked, inactive …). */
export const CARD_NOT_VERIFIABLE = "تعذّر التحقق من هذه البطاقة.";
export const CARD_VERIFY_RATE_LIMITED = "تعذّر التحقق الآن. يُرجى المحاولة لاحقًا.";

export const familyCardStatusLabels: Record<FamilyCardStatus, string> = {
  ACTIVE: "سارية",
  REVOKED: "ملغاة",
};

export const familyCardRevokeReasonLabels: Record<FamilyCardRevokeReason, string> = {
  REISSUED: "إعادة إصدار",
  ADMINISTRATIVE: "إلغاء إداري",
  COMPROMISED: "اشتباه في إساءة الاستخدام",
};
