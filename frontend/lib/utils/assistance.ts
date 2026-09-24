import type {
  AssistanceItem,
  ExecutionMode,
  ExportClassification,
  ReceiptMode,
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
  APPROVED: "معتمد",
  REJECTED: "مرفوض",
  NOT_DELIVERED: "لم يُسلَّم",
  REMOVED: "أُزيل الترشيح",
};

export const executionModeLabels: Record<ExecutionMode, string> = {
  INTERNAL: "تنفيذ داخلي (تسليم عبر Famboook)",
  EXTERNAL: "تنفيذ خارجي (كشف لجهة أخرى)",
};

export const executionModeShort: Record<ExecutionMode, string> = {
  INTERNAL: "داخلي",
  EXTERNAL: "خارجي",
};

export const receiptModeLabels: Record<ReceiptMode, string> = {
  PERSONAL: "المستفيد شخصيًا",
  DELEGATE: "الاستلام بالنيابة",
};

export const exportClassificationLabels: Record<ExportClassification, string> = {
  STANDARD: "عادي",
  CONTACT: "اتصال",
  SENSITIVE: "حساس",
};

export const relationshipLabels = { SON: "ابن", DAUGHTER: "ابنة" } as const;

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
