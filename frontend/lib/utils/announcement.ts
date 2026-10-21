import type { StatusTone } from "@/components/shared/status-badge";
import type {
  AnnouncementAudienceType,
  AnnouncementFailureCode,
  AnnouncementStatus,
  StatisticsBasis,
} from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";

// The wording of announcement management (FB-NOTIFY-01C.3c). Codes and states
// come from the server; nothing here decides what is allowed.

export const announcementStatusLabels: Record<AnnouncementStatus, string> = {
  DRAFT: "مسودة",
  QUEUED: "في انتظار النشر",
  PUBLISHING: "قيد النشر",
  PUBLISHED: "منشور",
  PUBLISH_FAILED: "تعذّر النشر",
  ARCHIVED: "مؤرشف",
  CANCELLED: "ملغى",
};

export const announcementStatusTones: Record<AnnouncementStatus, StatusTone> = {
  DRAFT: "neutral",
  QUEUED: "info",
  PUBLISHING: "info",
  PUBLISHED: "success",
  PUBLISH_FAILED: "danger",
  ARCHIVED: "neutral",
  CANCELLED: "neutral",
};

export const audienceTypeLabels: Record<AnnouncementAudienceType, string> = {
  STAFF_FAMILIES: "أسر محددة",
  STAFF_ALL_ACTIVE: "جميع الأسر النشطة",
  COORDINATOR_SCOPE: "كامل نطاق التنسيق",
  COORDINATOR_SELECTION: "أسر محددة من النطاق",
};

/** The stored criteria of audience_selection (counts only). */
export const audienceSelectionLabels: Record<string, string> = {
  family_ids: "أسر محددة",
  branch_ids: "فروع",
  branch_group_ids: "مجموعات فروع",
  clan_ids: "عشائر",
};

export const statisticsBasisLabels: Record<StatisticsBasis, string> = {
  ALL_RECIPIENTS: "كل مستلمي الإعلان",
  CURRENT_COORDINATOR_SCOPE: "الأسر الواقعة في نطاق التنسيق الحالي فقط",
  NO_CURRENT_SCOPE: "لا يوجد نطاق تنسيق حالي",
};

/** What a stored failure code means, and what the sender can do (R4 as decided). */
export const failureCodeExplanations: Record<AnnouncementFailureCode, string> = {
  SENDER_NOT_AUTHORIZED: "لم تعد لديك صلاحية الإرسال إلى هذا الجمهور عند النشر. لا يمكن إعادة المحاولة؛ يمكنك إلغاء الإعلان.",
  AUDIENCE_INVALID: "تحديد المستلمين لم يعد صالحًا عند النشر. لا يمكن إعادة المحاولة؛ يمكنك إلغاء الإعلان.",
  EXPIRED_BEFORE_PUBLICATION: "انتهت مدة الإعلان قبل نشره. لا يمكن إعادة المحاولة؛ يمكنك إلغاء الإعلان.",
  AUDIENCE_EMPTY: "لم توجد أسر مستلمة عند النشر. يمكنك إعادة المحاولة مرة واحدة أو إلغاء الإعلان.",
  PUBLICATION_ERROR: "حدث خطأ مؤقت أثناء النشر. يعيد النظام المحاولة تلقائيًا، ثم تتاح إعادة محاولة يدوية واحدة.",
  STALE_PUBLISHING: "توقفت عملية النشر قبل اكتمالها. يعيد النظام المحاولة تلقائيًا، ثم تتاح إعادة محاولة يدوية واحدة.",
};

export function failureExplanation(code: string | null): string | null {
  if (!code) return null;
  return failureCodeExplanations[code as AnnouncementFailureCode] ?? "تعذّر النشر لسبب غير معروف.";
}

/** The 503: only that THIS request was not carried out — never a claim about earlier deliveries. */
export const PUBLICATION_UNAVAILABLE_MESSAGE = "نشر الإشعارات غير متاح حاليًا، ولم يُنفَّذ هذا الطلب. لم تتغير حالة الإعلان؛ يمكنك المحاولة لاحقًا.";

const CODE_MESSAGES: Record<string, string> = {
  NOTIFICATION_ACTOR_NOT_ALLOWED: "لا تملك صلاحية تنفيذ هذا الإجراء على الإعلان.",
  NOTIFICATION_AUDIENCE_NOT_AUTHORIZED: "لا تملك صلاحية الإرسال إلى هذه الفئة من الأسر.",
  NOTIFICATION_NOT_FOUND: "هذا الإعلان غير متاح.",
  NOTIFICATION_INVALID_TRANSITION: "تغيّرت حالة الإعلان ولم يعد هذا الإجراء متاحًا. حدّثنا الصفحة بحالته الحالية.",
  NOTIFICATION_IDEMPOTENCY_CONFLICT: "رقم مرجع هذه المسودة استُخدم لمحتوى مختلف. راجع البيانات واحفظ مجددًا.",
  NOTIFICATION_RETRY_NOT_ALLOWED: "لا يمكن إعادة محاولة نشر هذا الإعلان، ويمكن إلغاؤه.",
  NOTIFICATION_RETRY_LIMIT_REACHED: "تم بلوغ الحد الأقصى لمحاولات النشر، ويمكن إلغاء الإعلان.",
  NOTIFICATION_AUTOMATIC_RETRY_PENDING: "سيعيد النظام محاولة النشر تلقائيًا؛ لا حاجة لإعادة المحاولة الآن.",
  NOTIFICATION_AUDIENCE_INVALID: "تحديد المستلمين غير صالح.",
  NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE: "إحدى الأسر المحددة غير متاحة للإرسال. راجع الأسر المختارة.",
  NOTIFICATION_AUDIENCE_EMPTY: "لا توجد أسر مستلمة ضمن هذا التحديد.",
  NOTIFICATION_AUDIENCE_NOT_IN_PILOT: "خلال التجربة المضبوطة يُرسل الإعلان إلى أسر التجربة المعتمدة فقط، بتحديدها أسرةً أسرة. اختر «أسر محددة» وأسر التجربة فقط.",
  NOTIFICATION_EXPIRY_TOO_SOON: "يجب أن يكون موعد انتهاء الإعلان بعد ساعة على الأقل.",
  NOTIFICATION_PUBLICATION_UNAVAILABLE: PUBLICATION_UNAVAILABLE_MESSAGE,
  COORDINATOR_SPACE_UNAVAILABLE: "مساحة التنسيق غير متاحة لهذا الحساب حاليًا.",
};

/** The stable code of an API refusal ({message, code}), if any. */
export function announcementErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

/** A safe Arabic message for a failed announcement request. */
export function announcementErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.";
  const code = announcementErrorCode(error);
  if (code && CODE_MESSAGES[code]) return CODE_MESSAGES[code];
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا تملك صلاحية تنفيذ هذا الإجراء.";
    case 404:
      return "هذا الإعلان غير متاح.";
    case 409:
      return "تغيّرت حالة الإعلان. حدّثنا الصفحة بحالته الحالية.";
    case 422:
      return "تحقق من البيانات المدخلة.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";
    case 503:
      return PUBLICATION_UNAVAILABLE_MESSAGE;
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
