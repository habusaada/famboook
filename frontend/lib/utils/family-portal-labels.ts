import type { FamilyMobileTrustState } from "@/lib/api/family-account";
import type { DeclarationSource } from "@/lib/api/family-household";
import type { DisplacementStatus, Gender } from "@/lib/types/api/family";

// The Family Portal display vocabulary (docs/11 §23a). A missing value is
// «غير مسجّل»; an explicit UNKNOWN enum is «غير معروف» (marital status, via
// maritalStatusLabels) or «الحالة غير مؤكدة» (life status); a declared count
// that was not declared is «غير مُعلن»; 0 is shown as 0.

export const NOT_RECORDED = "غير مسجّل";
export const NOT_DECLARED = "غير مُعلن";
export const NO_DECLARATION = "لا يوجد إقرار مسجّل";
export const LIFE_STATUS_UNKNOWN = "الحالة غير مؤكدة";
export const DEATH_DATE_UNKNOWN = "تاريخ الوفاة غير معروف";

export const genderLabels: Record<Gender, string> = { MALE: "ذكر", FEMALE: "أنثى" };

/** The life status of one person; DECEASED agrees with the person's gender. */
export function lifeStatusText(status: "ALIVE" | "DECEASED" | "UNKNOWN", gender: Gender | null): string {
  if (status === "ALIVE") return "على قيد الحياة";
  if (status === "DECEASED") return gender === "FEMALE" ? "متوفاة" : "متوفى";
  return LIFE_STATUS_UNKNOWN;
}

/** The family's displacement state; null = not collected, never "not displaced". */
export function familyDisplacementLabel(status: DisplacementStatus | null): string {
  if (status === "DISPLACED") return "نازحة";
  if (status === "NOT_DISPLACED") return "غير نازحة";
  return NOT_RECORDED;
}

/** Who declared the household figures (approved family-facing wording). */
export const declarationSourceLabels: Record<DeclarationSource, string> = {
  IMPORT: "مستورد من السجل السابق",
  PAPER_FORM: "استمارة ورقية",
  MANUAL_ENTRY: "إدخال يدوي",
  VERIFIED_SOURCE: "مصدر موثّق",
};

/**
 * The owner's view of their CURRENT mobile trust (PWA-3B.5). REVOKED is
 * «غير موثّق حاليًا»: the Staff lifecycle word «ملغى», the reason and the
 * history stay internal.
 */
export const mobileTrustOwnerLabels: Record<FamilyMobileTrustState, string> = {
  TRUSTED: "موثّق",
  STALE: "يحتاج إعادة توثيق",
  REVOKED: "غير موثّق حاليًا",
  UNVERIFIED: "غير موثّق",
  NO_MOBILE: "لا يوجد رقم جوال صالح",
  UNAVAILABLE: "تعذّر عرض حالة التوثيق حاليًا",
};
