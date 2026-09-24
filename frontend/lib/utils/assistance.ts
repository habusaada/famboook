import type {
  AssistanceItem,
  AssistanceStatus,
  AssistanceType,
  Currency,
  NominationSource,
  NomineeStatus,
} from "@/lib/types/api/assistance";

// The single canonical presentation of Assistance codes. The backend
// stores codes only; never repeat these labels in components.

export const ASSISTANCE_TYPES: AssistanceType[] = ["IN_KIND", "CASH", "SERVICE"];

export const assistanceTypeLabels: Record<AssistanceType, string> = {
  IN_KIND: "مساعدة عينية",
  CASH: "مساعدة نقدية",
  SERVICE: "خدمة",
};

export const ASSISTANCE_STATUSES: AssistanceStatus[] = ["DRAFT", "OPEN", "COMPLETED", "CANCELLED"];

export const assistanceStatusLabels: Record<AssistanceStatus, string> = {
  DRAFT: "مسودة",
  OPEN: "مفتوحة",
  COMPLETED: "مكتملة",
  CANCELLED: "ملغاة",
};

export const CURRENCIES: Currency[] = ["ILS", "USD", "JOD", "EUR"];

export const currencyLabels: Record<Currency, string> = {
  ILS: "شيكل (ILS)",
  USD: "دولار (USD)",
  JOD: "دينار (JOD)",
  EUR: "يورو (EUR)",
};

export const nominationSourceLabels: Record<NominationSource, string> = {
  TARGETING: "الاستهداف",
  MANUAL: "إضافة يدوية",
  NEED: "من احتياج",
};

export const nomineeStatusLabels: Record<NomineeStatus, string> = {
  NOMINATED: "مرشح",
  REMOVED: "أُزيل الترشيح",
};

// Minimal eligibility indicators shown in the targeting preview.
export const targetingIndicatorLabels = {
  has_pregnant_member: "يوجد حامل",
  has_breastfeeding_member: "يوجد مرضع",
  has_member_with_disability: "يوجد شخص ذو إعاقة",
  has_member_with_chronic_disease: "يوجد مرض مزمن",
} as const;

export function itemSummary(item: AssistanceItem): string {
  const parts = [item.item_name];
  if (item.quantity_per_beneficiary) {
    parts.push(`${item.quantity_per_beneficiary}${item.unit ? ` ${item.unit}` : ""}`);
  }
  if (item.unit_value && item.currency) parts.push(`${item.unit_value} ${item.currency}`);
  return parts.join(" — ");
}

export function plannedPeriod(start: string | null, end: string | null): string {
  if (!start && !end) return "—";
  return `${start ?? "…"} → ${end ?? "…"}`;
}
