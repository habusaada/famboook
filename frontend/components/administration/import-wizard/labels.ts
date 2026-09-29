import type { ImportBatchStatus, ImportMode, ImportRowStatus } from "@/lib/types/api/imports";

// Presentation of the Import Wizard's stable backend codes (docs/03 §96a).
// Arabic wording lives here only; the API stores and returns codes.

export const FIELD_LABELS: Record<string, string> = {
  source_family_key: "مفتاح العائلة",
  national_id: "رقم الهوية",
  full_name: "الاسم الكامل",
  birth_date: "تاريخ الميلاد",
  gender: "الجنس",
  marital_status: "الحالة الاجتماعية",
  original_residence_text: "الإقامة الأصلية",
  life_status_source: "حالة الوفاة",
  death_date: "تاريخ الوفاة",
  declared_household_size: "عدد أفراد الأسرة",
  declared_living_sons: "أبناء ذكور أحياء",
  declared_living_daughters: "أبناء إناث أحياء",
  mobile: "الجوال",
  wife_1_national_id: "هوية الزوجة 1",
  wife_1_name: "اسم الزوجة 1",
  wife_2_national_id: "هوية الزوجة 2",
  wife_2_name: "اسم الزوجة 2",
  wife_3_national_id: "هوية الزوجة 3",
  wife_3_name: "اسم الزوجة 3",
  wife_4_national_id: "هوية الزوجة 4",
  wife_4_name: "اسم الزوجة 4",
};

// Every staging issue code the backend emits (InitialFamilyRow).
export const ISSUE_LABELS: Record<string, string> = {
  MISSING_FAMILY_KEY: "مفتاح العائلة مفقود",
  FAMILY_KEY_FROM_FORMULA: "مفتاح العائلة ناتج عن معادلة Excel",
  FAMILY_KEY_TOO_LONG: "مفتاح العائلة أطول من الحد المسموح",
  MISSING_FULL_NAME: "الاسم الكامل مفقود",
  CELL_ERROR: "خطأ في خلية Excel",
  EXTRA_CELLS: "بيانات إضافية غير متوقعة",
};

/** Arabic label; an unknown future code never shows as a raw enum name. */
export function issueLabel(code: string): string {
  return ISSUE_LABELS[code] ?? "مشكلة غير مصنّفة";
}

// Family-key decisions (enum names never shown). No decision = لم يُحسم.
export const DECISION_LABELS: Record<string, string> = {
  MATCH_EXISTING_BRANCH: "ربط بفرع موجود",
  CREATE_NEW_BRANCH: "إنشاء فرع جديد",
  SAME_BRANCH_AS_KEY: "ربط بنفس فرع مفتاح آخر",
  NO_BRANCH: "بدون فرع",
};

// Reconciliation (Step 5). Codes are stable backend values.
export const RECON_STATUS_LABELS: Record<string, string> = {
  NEW: "جديد",
  UNCHANGED: "بدون تغيير",
  CHANGED: "تغييرات",
  DUPLICATE_IN_FILE: "مكرر في الملف",
  CONFLICT: "تعارض",
  REVIEW_REQUIRED: "يحتاج مراجعة",
};

export const RECON_ISSUE_LABELS: Record<string, string> = {
  HEAD_ID_EQUALS_SPOUSE_ID: "رقم هوية رب الأسرة مطابق لهوية إحدى الزوجات في الصف نفسه",
  REGISTRY_MULTIPLE_PERSONS: "رقم الهوية مسجل لأكثر من شخص في السجل",
  EXISTING_PERSON_NOT_HEAD: "الشخص موجود في السجل عضوًا غير رب أسرة في أسرة أخرى",
  FAMILY_IN_OTHER_CLAN: "الشخص رب أسرة في عشيرة أخرى",
  DUPLICATE_HEAD_ID_IN_FILE: "رقم هوية رب الأسرة نفسه ظهر في أكثر من صف",
  HEAD_ALSO_SPOUSE_IN_FILE: "رقم الهوية ظهر رب أسرة في صف وزوجة في صف آخر",
  POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD: "أسرة مستقلة لزوجة رب أسرة متعدد الزوجات",
  SPOUSE_SHARED_BY_LIVING_HEADS: "ظهرت الزوجة نفسها في أكثر من أسرة مع أرباب أسر أحياء",
  SPOUSE_REPEATED_HEAD_STATUS_UNKNOWN: "ظهرت الزوجة نفسها في أكثر من أسرة وحالة أحد أرباب الأسر غير معروفة",
  SPOUSE_REPEATED_AFTER_HEAD_DEATH: "الزوجة نفسها في أسرة أخرى لرب أسرة متوفى (زواج بعد الترمل)",
  DUPLICATE_SPOUSE_IN_ROW: "رقم هوية زوجة مكرر داخل الصف نفسه",
  SPOUSE_IN_OTHER_FAMILY: "الزوجة موجودة في السجل ضمن أسرة أخرى",
  SPOUSE_MULTIPLE_PERSONS: "رقم هوية الزوجة مسجل لأكثر من شخص",
  SPOUSE_NATIONAL_ID_FORMAT_VARIANT: "هوية الزوجة مسجلة في السجل بصيغة مختلفة",
  DELETED_PERSON_MATCH: "رقم الهوية يطابق شخصًا محذوفًا من السجل",
  NATIONAL_ID_FORMAT_VARIANT: "رقم الهوية مسجل في السجل بصيغة مختلفة",
  EXISTING_PERSON_IDENTITY_MISMATCH: "بيانات هوية الشخص (الميلاد أو الجنس) تختلف عن السجل",
  NO_ID_NAME_AMBIGUITY: "لا يوجد رقم هوية ويوجد اسم مطابق — لا تتم المطابقة بالاسم",
  LIFE_STATUS_INCONSISTENT: "تاريخ وفاة مع حالة غير «متوفى»",
  UNMAPPED_SOURCE_VALUE: "قيمة غير معروفة في الملف",
  STAGING_REJECTED: "الصف مرفوض بنيويًا",
  EXISTING_PERSON_NO_FAMILY: "الشخص موجود في السجل دون أسرة",
  SPOUSE_EXISTING_PERSON: "الزوجة موجودة في السجل",
};

export const HEAD_MATCH_LABELS: Record<string, string> = {
  NO_NATIONAL_ID: "بلا رقم هوية",
  NO_EXISTING_PERSON: "شخص جديد",
  EXISTING_PERSON: "شخص موجود",
  MULTIPLE_PERSONS: "أكثر من شخص",
  DELETED_PERSON: "شخص محذوف",
  FORMAT_VARIANT: "صيغة هوية مختلفة",
};

export const FAMILY_MATCH_LABELS: Record<string, string> = {
  NO_EXISTING_FAMILY: "أسرة جديدة",
  EXISTING_FAMILY: "أسرة موجودة",
  OTHER_CLAN_FAMILY: "أسرة في عشيرة أخرى",
  PERSON_NOT_HEAD: "ليس رب أسرة",
  NOT_DETERMINED: "غير محدد",
};

export const DIFF_FIELD_LABELS: Record<string, string> = {
  full_name: "الاسم",
  birth_date: "تاريخ الميلاد",
  gender: "الجنس",
  marital_status: "الحالة الاجتماعية",
  mobile: "الجوال",
  life_status: "الحالة",
  death_date: "تاريخ الوفاة",
  branch: "الفرع",
  original_residence_text: "الإقامة الأصلية",
  declared_household_size: "عدد أفراد الأسرة",
  declared_living_sons: "أبناء ذكور أحياء",
  declared_living_daughters: "أبناء إناث أحياء",
};

export const MODE_LABELS: Record<ImportMode, { title: string; description: string }> = {
  INITIAL: {
    title: "استيراد أولي",
    description: "أول تعبئة منضبطة لبيانات أسر العشيرة.",
  },
  INCREMENTAL: {
    title: "تحديث بيانات العشيرة",
    description:
      "ملف أحدث قد يتضمن سجلات سبق استيرادها وسجلات جديدة ومتغيرة ومكررة. لا يُحذف أي سجل لغيابه عن الملف.",
  },
};

export const BATCH_STATUS_LABELS: Record<ImportBatchStatus, string> = {
  UPLOADED: "بانتظار تعيين الأعمدة",
  VALIDATING: "قيد التجهيز",
  READY_FOR_REVIEW: "جاهزة للمراجعة",
  READY_TO_APPLY: "جاهزة للاعتماد",
  APPLYING: "قيد التطبيق",
  APPLIED: "مطبّقة",
  FAILED: "فاشلة",
};

// FLAGGED = needs review (not rejected); PENDING = staged without issues.
export const ROW_STATUS_LABELS: Partial<Record<ImportRowStatus, string>> = {
  PENDING: "جاهز",
  VALID: "جاهز",
  FLAGGED: "يحتاج مراجعة",
  REJECTED: "مرفوض",
};

export function fmt(n: number): string {
  return n.toLocaleString("ar");
}

export function formatBytes(bytes: number | null): string {
  if (bytes === null) return "—";
  if (bytes < 1024) return `${fmt(bytes)} بايت`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString("ar", { maximumFractionDigits: 1 })} ك.ب`;
  return `${(bytes / (1024 * 1024)).toLocaleString("ar", { maximumFractionDigits: 1 })} م.ب`;
}
