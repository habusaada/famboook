import type { StatusTone } from "@/components/shared/status-badge";
import type {
  CivilImportAction,
  CivilImportFile,
  CivilImportPhase,
  CivilImportReleaseStatus,
  CivilImportRequestStatus,
  CivilImportRequestType,
  CivilImportRunStatus,
} from "@/lib/types/api/civil-registry";

// FB-CIVIL-01A-UI U1 — Arabic labels for the civil identity import screens.
// Every value shown is a fixed code or a count; an unknown code is shown
// as the code itself (codes are uppercase identifiers, never source data).

export const fmt = (n: number) => n.toLocaleString("ar");

export function dateLabel(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("ar", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

export const PHASE_LABELS: Record<CivilImportPhase, string> = {
  STAGING: "جارٍ التحميل",
  STAGING_INTERRUPTED: "توقف التحميل — قابل للاستئناف",
  STAGED: "تم التحميل",
  PREPARING: "جارٍ التحضير",
  PREPARED: "تم التحضير",
  VERIFIED: "تم التحقق",
  AWAITING_APPROVAL: "بانتظار اعتماد النشر",
  APPROVED: "اعتُمد النشر — بانتظار التنفيذ",
  PUBLISHED: "منشور",
  REJECTED: "مرفوض",
  ABORTED: "أُلغي",
  FAILED: "فشل",
  NOTHING_TO_DO: "لا تغييرات",
};

export const PHASE_TONES: Record<CivilImportPhase, StatusTone> = {
  STAGING: "info",
  STAGING_INTERRUPTED: "warning",
  STAGED: "neutral",
  PREPARING: "info",
  PREPARED: "neutral",
  VERIFIED: "success",
  AWAITING_APPROVAL: "warning",
  APPROVED: "info",
  PUBLISHED: "success",
  REJECTED: "danger",
  ABORTED: "neutral",
  FAILED: "danger",
  NOTHING_TO_DO: "neutral",
};

/** The ordered happy path shown by the phase stepper. */
export const PHASE_STEPS: CivilImportPhase[] = ["STAGING", "STAGED", "PREPARING", "PREPARED", "VERIFIED", "PUBLISHED"];

export const RUN_STATUS_LABELS: Record<CivilImportRunStatus, string> = {
  RUNNING: "قيد التشغيل",
  SUCCEEDED: "نجح",
  FAILED: "فشل",
  REJECTED: "مرفوض",
  NOTHING_TO_DO: "لا تغييرات",
};

export const RELEASE_STATUS_LABELS: Record<CivilImportReleaseStatus, string> = {
  BUILDING: "قيد البناء",
  PUBLISHED: "منشور",
  ABANDONED: "متروك",
};

export const RELEASE_STATUS_TONES: Record<CivilImportReleaseStatus, StatusTone> = {
  BUILDING: "info",
  PUBLISHED: "success",
  ABANDONED: "neutral",
};

export const PREPARATION_STATUS_LABELS: Record<string, string> = {
  PREPARING: "جارٍ التحضير",
  PREPARED: "تم التحضير",
  VERIFIED: "تم التحقق",
};

export const FILE_STATUS_LABELS: Record<CivilImportFile["status"], string> = {
  PENDING: "بانتظار التحميل",
  VERIFIED: "تم التحقق من البصمة",
  STAGED: "تم التحميل",
  FAILED: "فشل",
};

export const FILE_STATUS_TONES: Record<CivilImportFile["status"], StatusTone> = {
  PENDING: "neutral",
  VERIFIED: "info",
  STAGED: "success",
  FAILED: "danger",
};

export const WORKER_LABELS: Record<string, string> = {
  IDLE: "متوقف (جاهز)",
  BUSY: "يعمل",
  STALE: "لا يستجيب",
  NOT_INSTALLED: "غير مثبّت بعد",
};

export const POLICY_LABELS: Record<string, string> = {
  ACCEPT_SOURCE: "اعتماد قيمة المصدر",
  PRESERVE_EXISTING_ON_BLANK: "الإبقاء على القيمة الحالية عند الفراغ",
  UNRESOLVED: "غير محسوم",
  ZERO_UNRESOLVED_QUARANTINE: "لا حجر غير محسوم",
  THRESHOLD: "حد أقصى للحجر",
};

export const COUNT_LABELS: Record<string, string> = {
  staged: "السجلات المحمّلة",
  new: "جديدة",
  changed: "متغيرة",
  unchanged: "دون تغيير",
  conflict: "متعارضة",
  carried_forward: "مرحّلة من الإصدار السابق",
  blank_over_value: "فراغ فوق قيمة",
  expected_presence: "الحضور المتوقع",
};

export const ISSUE_LABELS: Record<string, string> = {
  QUARANTINE_ROW: "سطر محجور",
  MAINID_MISSING: "رقم الهوية الرئيسي مفقود",
  MAINID_DUPLICATE: "رقم الهوية الرئيسي مكرر",
  MAINID_TOO_LONG: "رقم الهوية الرئيسي أطول من المسموح",
  MAINID_SURROUNDING_SPACE: "مسافات حول رقم الهوية الرئيسي",
  FATHERID_UNRESOLVED: "هوية الأب غير موجودة في الملف",
  MOTHERID_UNRESOLVED: "هوية الأم غير موجودة في الملف",
  PARENT_IS_SELF: "أحد الوالدين هو الشخص نفسه",
  PARTNERID_POPULATED: "حقل الزوج معبأ",
  FIELD_COUNT: "عدد الحقول غير صحيح",
  INVALID_UTF8: "ترميز غير صالح",
  NUL_BYTE: "محرف فارغ في السطر",
  RECORD_TOO_LONG: "سطر أطول من المسموح",
  UNTERMINATED_QUOTE: "علامة اقتباس غير مغلقة",
  QUOTE_IN_UNQUOTED_FIELD: "علامة اقتباس داخل حقل غير مقتبس",
  TEXT_AFTER_CLOSING_QUOTE: "نص بعد علامة الاقتباس",
  BLANK_OVER_VALUE: "فراغ فوق قيمة موجودة",
  CONFLICT: "تعارض",
};

export const EVENT_LABELS: Record<string, string> = {
  SOURCE_CATALOGUED: "فهرسة مصدر",
  SOURCE_UNAVAILABLE: "مصدر غير متاح",
  REQUEST_QUEUED: "إضافة طلب إلى الطابور",
  REQUEST_CANCELLED: "إلغاء طلب",
  REQUEST_STARTED: "بدء طلب",
  REQUEST_SUCCEEDED: "نجاح طلب",
  REQUEST_FAILED: "فشل طلب",
  PUBLICATION_REQUESTED: "طلب نشر",
  PUBLICATION_WITHDRAWN: "سحب طلب نشر",
  PUBLICATION_APPROVED: "اعتماد نشر",
  PUBLICATION_REJECTED: "رفض نشر",
  PUBLICATION_EXPIRED: "انتهاء صلاحية طلب نشر",
  PUBLICATION_REFUSED: "رفض النظام للنشر",
  RELEASE_PUBLISHED: "نشر إصدار",
  LOCATIONS_VIEWED: "عرض مواقع الملفات",
};

export const DETAIL_LABELS: Record<string, string> = {
  scope: "النطاق",
  code: "الرمز",
  type: "النوع",
  count: "العدد",
  rows: "السطور",
  files: "الملفات",
  file_no: "رقم الملف",
  page: "الصفحة",
  duration_seconds: "المدة (ث)",
  expected_presence: "الحضور المتوقع",
};

export const SCOPE_LABELS: Record<string, string> = {
  FILES: "أسماء الملفات",
  ISSUES: "مواقع الملاحظات",
};

export const label = (map: Record<string, string>, code: string | null | undefined) => (code ? (map[code] ?? code) : "—");

// ---- U2: execution requests

export const REQUEST_TYPE_LABELS: Record<CivilImportRequestType, string> = {
  VALIDATE_SOURCE: "التحقق من المصدر",
  STAGE: "التحميل",
  ABORT: "إلغاء العملية",
  ASSESS: "التقييم والمعاينة",
  PREPARE: "تحضير الإصدار",
  VERIFY: "التحقق من الإصدار",
  PUBLISH: "نشر الإصدار",
};

export const REQUEST_STATUS_LABELS: Record<CivilImportRequestStatus, string> = {
  QUEUED: "في الانتظار",
  RUNNING: "قيد التنفيذ",
  SUCCEEDED: "نجح",
  FAILED: "فشل",
  CANCELLED: "أُوقف",
};

export const REQUEST_STATUS_TONES: Record<CivilImportRequestStatus, StatusTone> = {
  QUEUED: "info",
  RUNNING: "info",
  SUCCEEDED: "success",
  FAILED: "danger",
  CANCELLED: "neutral",
};

/** The operator's actions; each records a request that the worker executes. */
export const ACTION_LABELS: Record<CivilImportAction, string> = {
  STAGE: "استئناف التحميل",
  ASSESS: "تقييم ومعاينة",
  PREPARE: "تحضير الإصدار",
  VERIFY: "التحقق من الإصدار",
  ABORT: "إلغاء العملية",
  REQUEST_PUBLICATION: "طلب نشر",
};

export const ACTION_CONFIRMATIONS: Record<CivilImportAction, string> = {
  STAGE: "سيستأنف عامل الاستيراد تحميل الملفات من آخر نقطة محفوظة.",
  ASSESS: "سيقيّم عامل الاستيراد البيانات المحمّلة ويقارنها بالإصدار النشط دون كتابة أي شيء.",
  PREPARE: "سيبني عامل الاستيراد الإصدار المرشح وفق السياسة المعتمدة. لا يتغير الإصدار النشط ولا يُنشر شيء.",
  VERIFY: "سيتحقق عامل الاستيراد من أن الإصدار المرشح يطابق تصنيفه تمامًا. لا يُنشر شيء.",
  REQUEST_PUBLICATION: "سيُفتح طلب نشر لهذا الإصدار. لا يُنشر شيء حتى يعتمده مستخدم آخر خلال 24 ساعة وتتحقق كل الشروط لدى عامل الاستيراد.",
  ABORT: "ستُغلق العملية نهائيًا ويُترك إصدارها المرشح ويُحذف تحميلها. لا يتأثر الإصدار النشط. لا يمكن التراجع.",
};

export const ABORT_REASON_LABELS: Record<string, string> = {
  OPERATOR_DECISION: "قرار المشغّل",
  SOURCE_REPLACED: "استُبدل المصدر بآخر",
  DATA_PROBLEM: "مشكلة في البيانات",
};

export const SOURCE_PROBLEM_LABELS: Record<string, string> = {
  CHECKSUM_MISMATCH: "بصمة ملف لا تطابق ملف البيان",
  ROW_COUNT_MISMATCH: "عدد السطور لا يطابق ملف البيان",
  HEADER_MISMATCH: "ترويسة الأعمدة غير مطابقة",
  BOM_MISSING: "علامة الترميز مفقودة",
  EMPTY_FILE: "ملف فارغ",
  UNREADABLE: "ملف غير قابل للقراءة",
  FILE_MISSING: "ملف مذكور في البيان غير موجود",
  FILE_IS_SYMLINK: "ملف هو رابط رمزي (مرفوض)",
  FILE_OUTSIDE_DIRECTORY: "ملف خارج مجلد المصدر (مرفوض)",
  UNEXPECTED_ENTRIES: "ملفات غير مذكورة في البيان",
  MANIFEST_MISSING: "ملف البيان مفقود",
  MANIFEST_NOT_JSON: "ملف البيان ليس JSON صالحًا",
  MANIFEST_NOT_AN_OBJECT: "بنية ملف البيان غير صالحة",
  MANIFEST_TOO_LARGE: "ملف البيان أكبر من المسموح",
  MANIFEST_TABLE_INVALID: "اسم الجدول في البيان غير صالح",
  MANIFEST_COLUMNS_MISMATCH: "أعمدة البيان لا تطابق الأعمدة المعتمدة",
  MANIFEST_ENCODING_NOT_UTF8: "ترميز البيان ليس UTF-8",
  MANIFEST_TOTAL_ROWS_INVALID: "إجمالي السطور في البيان غير صالح",
  MANIFEST_TOTAL_ROWS_MISMATCH: "إجمالي السطور لا يساوي مجموع الملفات",
  MANIFEST_FILES_INVALID: "قائمة الملفات في البيان غير صالحة",
  MANIFEST_FILE_NAME_UNSAFE: "اسم ملف غير آمن في البيان",
  MANIFEST_FILE_DUPLICATED: "ملف مكرر في البيان",
  MANIFEST_FILE_ENTRY_INVALID: "مدخل ملف غير صالح في البيان",
  MANIFEST_FILE_COUNT_NOT_APPROVED: "عدد الملفات غير المعتمد",
  MANIFEST_ROW_COUNT_NOT_APPROVED: "عدد السطور غير المعتمد",
  DIRECTORY_INVALID: "مجلد المصدر غير صالح",
  SOURCE_CHANGED: "تغيّر ملف أثناء التحميل",
  OTHER: "ملاحظة أخرى",
};

export const FATAL_LABELS: Record<string, string> = {
  RUN_NOT_FOUND: "العملية غير موجودة",
  RUN_NOT_RUNNING: "العملية ليست مفتوحة",
  SOURCE_TABLE_UNSUPPORTED: "الجدول المصدر غير مدعوم",
  STAGING_INCOMPLETE: "التحميل غير مكتمل",
  CHECKSUM_NOT_VERIFIED: "لم يُتحقق من البصمات",
  ROW_COUNT_INCONSISTENT: "أعداد السطور غير متسقة",
  RELEASE_STATE_INVALID: "حالة الإصدار غير صالحة",
  COMPARISON_POLICY_UNSUPPORTED: "سياسة المقارنة غير مدعومة",
  QUARANTINE_NOT_ALLOWED: "السياسة لا تسمح بأي سطر محجور",
  QUARANTINE_THRESHOLD_EXCEEDED: "تجاوز حد الحجر",
  QUARANTINE_UNRESOLVED: "سياسة الحجر غير محسومة",
  BLANK_FIELD_UNRESOLVED: "سياسة الحقول الفارغة غير محسومة",
};

// ---- U3: two-person publication

export const PUBLICATION_STATUS_LABELS: Record<string, string> = {
  PENDING_APPROVAL: "بانتظار الاعتماد",
  APPROVED: "معتمد — بانتظار التنفيذ",
  REJECTED: "مرفوض",
  WITHDRAWN: "مسحوب",
  EXPIRED: "منتهي الصلاحية",
  EXECUTED: "نُشر",
  FAILED: "لم يُنشر",
};

export const PUBLICATION_STATUS_TONES: Record<string, StatusTone> = {
  PENDING_APPROVAL: "warning",
  APPROVED: "info",
  REJECTED: "danger",
  WITHDRAWN: "neutral",
  EXPIRED: "neutral",
  EXECUTED: "success",
  FAILED: "danger",
};

export const JUSTIFICATION_LABELS: Record<string, string> = {
  ROUTINE_REFRESH: "تحديث دوري",
  INITIAL_LOAD: "تحميل أولي",
  CORRECTION: "تصحيح",
};

export const REJECTION_LABELS: Record<string, string> = {
  COUNTS_UNEXPECTED: "أعداد غير متوقعة",
  NOT_AUTHORIZED_NOW: "غير مأذون به حاليًا",
  NEEDS_REVIEW: "يحتاج إلى مراجعة",
  OTHER_DECISION: "قرار آخر",
};

export const PUBLICATION_OUTCOME_LABELS: Record<string, string> = {
  PUBLISHED: "نُشر الإصدار",
  ALREADY_PUBLISHED: "كان منشورًا مسبقًا",
  RECONCILED_PUBLISHED: "نُشر (تمت المطابقة بعد انقطاع العامل)",
  TIMEOUT: "انقضت 24 ساعة دون قرار",
  BASE_CHANGED: "تغيّر الإصدار النشط منذ تقديم الطلب",
  RELEASE_CHANGED: "لم يعد الإصدار متحققًا منه",
  RELEASE_ABANDONED: "أُلغيت العملية",
  AUTHORIZATION_MISSING: "لا يوجد مرجع تفويض بالبيانات الحقيقية لدى العامل",
  PUBLICATION_GATE_CLOSED: "بوابة النشر مغلقة لدى العامل",
  PUBLICATION_EXPIRED: "انتهت صلاحية الاعتماد قبل التنفيذ",
  NOT_APPROVED_FOR_PUBLICATION: "لا يوجد اعتماد صالح",
  PUBLICATION_FAILED: "تعذّر النشر وأُلغي؛ الإصدار السابق لم يتغير",
  WORKER_LOST: "توقف العامل قبل النشر",
  CANCELLED_BY_OPERATOR: "أُوقف بطلب من المشغّل",
};
