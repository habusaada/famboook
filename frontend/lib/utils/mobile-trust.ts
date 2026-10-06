import type { StatusTone } from "@/components/shared/status-badge";
import type {
  MobileTrustRevokeReason,
  MobileTrustRowStatus,
  MobileTrustState,
  MobileVerificationMethod,
  StaffMobileVerificationMethod,
} from "@/lib/types/api/mobile-trust";

// docs/03 §89b, docs/06 §22b — Staff labels for mobile trust (FU-15).

export const mobileTrustStateLabels: Record<MobileTrustState, string> = {
  TRUSTED: "موثّق",
  STALE: "يحتاج إعادة توثيق",
  REVOKED: "ملغى",
  UNVERIFIED: "غير موثّق",
  NO_MOBILE: "لا يوجد رقم جوال صالح",
  UNAVAILABLE: "تعذّر تحديد حالة التوثيق",
};

export const mobileTrustStateTones: Record<MobileTrustState, StatusTone> = {
  TRUSTED: "success",
  STALE: "warning",
  REVOKED: "danger",
  UNVERIFIED: "neutral",
  NO_MOBILE: "neutral",
  UNAVAILABLE: "neutral",
};

export const mobileTrustRowLabels: Record<MobileTrustRowStatus, string> = {
  TRUSTED: "موثّق",
  STALE: "متقادم",
  REVOKED: "ملغى",
};

export const mobileTrustRowTones: Record<MobileTrustRowStatus, StatusTone> = {
  TRUSTED: "success",
  STALE: "warning",
  REVOKED: "danger",
};

export const mobileVerificationMethodLabels: Record<MobileVerificationMethod, string> = {
  IN_PERSON: "تحقق حضوري",
  STAFF_CALLBACK: "تحقق عبر اتصال الموظف",
  AUTHORIZED_RECORD_REVIEW: "مراجعة سجل موثوق",
  // History only: established by the first-activation code, never by Staff.
  SELF_OTP: "تحقق ذاتي برمز التفعيل",
};

/** The only methods a Staff grant may use (MobileVerificationMethod::staffMethods). */
export const STAFF_MOBILE_VERIFICATION_METHODS = [
  "IN_PERSON",
  "STAFF_CALLBACK",
  "AUTHORIZED_RECORD_REVIEW",
] as const satisfies readonly StaffMobileVerificationMethod[];

export const mobileTrustRevokeReasonLabels: Record<MobileTrustRevokeReason, string> = {
  REPORTED_LOST: "الإبلاغ عن فقدان الجوال",
  NOT_OWNER: "الرقم لا يعود لهذا الشخص",
  VERIFICATION_ERROR: "خطأ في التحقق السابق",
  ADMINISTRATIVE: "إلغاء إداري",
};

export const MOBILE_TRUST_REVOKE_REASONS = [
  "REPORTED_LOST",
  "NOT_OWNER",
  "VERIFICATION_ERROR",
  "ADMINISTRATIVE",
] as const satisfies readonly MobileTrustRevokeReason[];
