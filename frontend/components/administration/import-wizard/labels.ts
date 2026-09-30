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
  PARTIALLY_APPLIED: "مطبّقة جزئيًا",
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

// ---- Step 6 Dry Run (docs/03 §96b). Codes are stable backend values. -------

export const INTENT_LABELS: Record<string, string> = {
  CREATE: "إنشاء",
  REUSE: "استخدام الموجود",
  // An intentional planner decision, not a failure.
  OMIT: "تجاوز",
  BLOCK: "متعذر",
};

export const EFFECT_GROUP_LABELS: Record<string, string> = {
  families: "الأسر",
  head_persons: "أرباب الأسر (أشخاص)",
  head_memberships: "عضويات رب الأسرة",
  spouse_persons: "الأزواج والزوجات (أشخاص)",
  spouse_memberships: "عضويات الزوج / الزوجة",
  declarations: "الإقرارات الأسرية",
  residences: "الإقامة الأصلية",
};

export const PRECONDITION_LABELS: Record<string, string> = {
  BATCH_NOT_INITIAL: "المعاينة متاحة للاستيراد الأولي فقط في هذه المرحلة",
  BATCH_NOT_READY_FOR_REVIEW: "الدفعة ليست في حالة جاهزة للمراجعة",
  APPLY_ALREADY_STARTED: "بدأ تطبيق هذه الدفعة سابقًا",
  MAPPING_NOT_CONFIRMED: "تعيين الأعمدة غير معتمد",
  NO_STAGED_ROWS: "لا توجد صفوف مجهزة",
  FAMILY_KEYS_UNRESOLVED: "توجد مفاتيح أسر غير محسومة",
  RECONCILIATION_NOT_RUN: "لم تُنفَّذ المطابقة مع السجل بعد",
  RECONCILIATION_STALE: "نتائج المطابقة قديمة — أعد المطابقة",
  ROWS_NOT_RECONCILED: "صفوف بلا نتيجة مطابقة",
  ROWS_UNCHANGED: "صفوف «بدون تغيير» — الاستيراد الأولي يخطط للصفوف الجديدة فقط",
  ROWS_CHANGED: "صفوف بتغييرات تحتاج مراجعة",
  ROWS_DUPLICATE_IN_FILE: "صفوف مكررة في الملف",
  ROWS_CONFLICT: "صفوف بتعارض",
  ROWS_REVIEW_REQUIRED: "صفوف تحتاج مراجعة",
  ROWS_ALREADY_APPLIED: "صفوف مرتبطة بأسر مسبقًا",
  APPLY_RECORDS_EXIST: "توجد سجلات تطبيق سابقة لهذه الدفعة",
  CLAN_INACTIVE: "العشيرة المستهدفة غير مفعّلة",
  RELATIONSHIP_TYPE_HEAD_UNAVAILABLE: "صلة «رب الأسرة» غير متاحة في السجل المرجعي",
  RELATIONSHIP_TYPE_SPOUSE_UNAVAILABLE: "صلة «زوج/زوجة» غير متاحة في السجل المرجعي",
  BRANCH_NOT_SELECTABLE: "فرع مستهدف غير متاح (معطّل أو لا يتبع العشيرة)",
};

export const PLAN_REASON_LABELS: Record<string, string> = {
  // non-blocking evidence
  HOUSEHOLD_HEAD_DECEASED: "رب الأسرة متوفى — تبقى الأسرة ويبقى ربّها كما في المصدر",
  HEAD_PERSON_REUSED: "رب الأسرة موجود في السجل — يُستخدم دون تعديل",
  HEAD_WITHOUT_NATIONAL_ID: "رب أسرة بلا رقم هوية — لا مطابقة بالاسم",
  INDEPENDENT_HOUSEHOLD_HEAD: "الزوج/الزوجة رب أسرة مستقلة — بلا عضوية في هذه الأسرة",
  HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD: "علاقة سابقة (رب أسرة متوفى) — بلا عضوية نشطة",
  PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP: "الشخص عضو نشط في أسرة أخرى — بلا عضوية جديدة",
  SPOUSE_WITHOUT_NATIONAL_ID: "زوج/زوجة بلا رقم هوية — لا يُنشأ ولا يُطابق بالاسم",
  NO_DECLARED_VALUES: "لا توجد قيم معلنة للأسرة",
  NO_ORIGINAL_RESIDENCE: "لا توجد إقامة أصلية في المصدر",
  // blocking
  ROW_REJECTED: "الصف مرفوض بنيويًا",
  HEAD_ID_DUPLICATED_IN_FILE: "رقم هوية رب الأسرة مكرر في الملف",
  HEAD_MULTIPLE_PERSONS: "رقم الهوية مسجل لأكثر من شخص",
  HEAD_DELETED_PERSON: "رقم الهوية يطابق شخصًا محذوفًا",
  HEAD_HAS_ACTIVE_MEMBERSHIP: "رب الأسرة عضو نشط في أسرة أخرى",
  HEAD_IDENTITY_MISMATCH: "بيانات هوية رب الأسرة تختلف عن السجل",
  HEAD_WITHOUT_NAME: "رب الأسرة بلا اسم",
  HEAD_UNMAPPED_SOURCE_VALUE: "قيمة غير معروفة في بيانات رب الأسرة",
  HEAD_INVALID_DEATH_DATE: "تاريخ وفاة رب الأسرة غير صالح",
  HEAD_DEATH_DATE_WITHOUT_DECEASED: "تاريخ وفاة مع حالة غير «متوفى»",
  FAMILY_KEY_MISSING: "مفتاح الأسرة مفقود",
  FAMILY_KEY_UNRESOLVED: "مفتاح الأسرة غير محسوم",
  BRANCH_NOT_SELECTABLE: "الفرع المستهدف غير متاح",
  SPOUSE_MULTIPLE_PERSONS: "رقم هوية الزوج/الزوجة مسجل لأكثر من شخص",
  SPOUSE_DELETED_PERSON: "رقم هوية الزوج/الزوجة يطابق شخصًا محذوفًا",
  SPOUSE_DUPLICATED_IN_ROW: "الزوج/الزوجة مكرر في الصف نفسه",
  SPOUSE_SHARED_UNRESOLVED: "الزوجة نفسها مع أكثر من رب أسرة حي أو غير معروف",
  SPOUSE_GENDER_CONFLICT: "جنس الزوج/الزوجة متعارض بين الصفوف",
  SPOUSE_GENDER_UNDERIVABLE: "تعذّر تحديد جنس الزوج/الزوجة (جنس رب الأسرة غير معروف)",
  SPOUSE_WITHOUT_NAME: "الزوج/الزوجة بلا اسم",
  SPOUSE_OWNER_BLOCKED: "الشخص نفسه متعذر في صف آخر",
  HEAD_ID_EQUALS_SPOUSE_ID: "هوية رب الأسرة مطابقة لهوية زوجة في الصف نفسه",
  DECLARATION_OUT_OF_RANGE: "قيمة معلنة خارج النطاق",
};

// Intentional, non-blocking omissions (OMIT) — shown apart from blocking reasons.
export const OMIT_REASON_CODES = new Set([
  "INDEPENDENT_HOUSEHOLD_HEAD",
  "HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD",
  "PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP",
  "SPOUSE_WITHOUT_NATIONAL_ID",
  "NO_DECLARED_VALUES",
  "NO_ORIGINAL_RESIDENCE",
]);

export function planReasonLabel(code: string): string {
  return PLAN_REASON_LABELS[code] ?? "سبب غير مصنّف";
}

export const DRY_RUN_FILTER_LABELS: Record<string, string> = {
  all: "الكل",
  executable: "قابلة للتنفيذ",
  blocked: "متعذرة",
  warnings: "بها ملاحظات",
};
