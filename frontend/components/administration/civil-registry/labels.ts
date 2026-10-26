import type { StatusTone } from "@/components/shared/status-badge";
import type { CivilImportFile, CivilImportPhase, CivilImportReleaseStatus, CivilImportRunStatus } from "@/lib/types/api/civil-registry";

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
  STAGED: "تم التحميل",
  PREPARING: "جارٍ التحضير",
  PREPARED: "تم التحضير",
  VERIFIED: "تم التحقق",
  PUBLISHED: "منشور",
  REJECTED: "مرفوض",
  ABORTED: "أُلغي",
  FAILED: "فشل",
  NOTHING_TO_DO: "لا تغييرات",
};

export const PHASE_TONES: Record<CivilImportPhase, StatusTone> = {
  STAGING: "info",
  STAGED: "neutral",
  PREPARING: "info",
  PREPARED: "neutral",
  VERIFIED: "success",
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
