import type { StatusTone } from "@/components/shared/status-badge";
import { ApiError } from "@/lib/api/client";
import type {
  FollowupCloseReason,
  FollowupContactOutcome,
  FollowupEvent,
  FollowupOutcome,
  FollowupStage,
  FollowupStatus,
  FollowupSummary,
  FollowupTriggerReason,
} from "@/lib/api/followups";

// The wording of follow-up management (FB-NOTIFY-01E.3-F3.3). Codes, states
// and permissions come from the server; nothing here decides what is allowed.

export const followupStatusLabels: Record<FollowupStatus, string> = { OPEN: "مفتوحة", CLOSED: "مغلقة" };
export const followupStatusTones: Record<FollowupStatus, StatusTone> = { OPEN: "info", CLOSED: "neutral" };

export const followupStageLabels: Record<FollowupStage, string> = { FIRST: "المرحلة الأولى", SECOND: "المرحلة الثانية" };

export const followupTriggerLabels: Record<FollowupTriggerReason, string> = {
  UNVIEWED: "لم تطّلع الأسرة على الإعلان",
  UNACKNOWLEDGED: "لم تؤكد الأسرة الاطلاع على الإعلان",
};

export const followupOutcomeLabels: Record<FollowupOutcome, string> = {
  SATISFIED: "استجابت الأسرة",
  EXPIRED: "انتهت مدة الإعلان",
  CLOSED_MANUALLY: "أُغلقت يدويًا",
};
export const followupOutcomeTones: Record<FollowupOutcome, StatusTone> = { SATISFIED: "success", EXPIRED: "neutral", CLOSED_MANUALLY: "brand" };

/** Contact outcomes (ADR §2c). CALLED_INFORMED means told — never acknowledged. */
export const contactOutcomeLabels: Record<FollowupContactOutcome, string> = {
  CALLED_INFORMED: "اتصال وأُبلغت الأسرة",
  CALLED_NO_ANSWER: "اتصال دون رد",
  VISITED: "زيارة",
  MESSAGE_LEFT: "تُركت رسالة",
  OTHER: "أخرى",
};

export const closeReasonLabels: Record<FollowupCloseReason, string> = {
  INFORMED_BY_PHONE: "أُبلغت الأسرة هاتفيًا",
  INFORMED_IN_PERSON: "أُبلغت الأسرة حضوريًا",
  FAMILY_UNREACHABLE: "تعذّر الوصول إلى الأسرة",
  FAMILY_INACTIVE: "الأسرة غير نشطة",
  NOT_APPLICABLE: "لا تنطبق المتابعة",
};

export const eventTypeLabels: Record<string, string> = {
  FOLLOWUP_OPENED: "فُتحت المتابعة",
  CONTACT_RECORDED: "سُجّل تواصل",
  FOLLOWUP_CLOSED: "أُغلقت المتابعة",
};

const ACTOR_LABELS: Record<string, string> = {
  SYSTEM: "النظام",
  STAFF: "الإدارة المركزية",
  YOU: "أنت",
  COORDINATOR: "منسّق",
  FAMILY: "الأسرة",
};

/** Who acted, as this reader may know it: a name for Staff, a label for a coordinator. */
export function eventActorLabel(event: FollowupEvent): string {
  if ("label" in event.actor) return ACTOR_LABELS[event.actor.label] ?? "—";
  return event.actor.name ?? ACTOR_LABELS[event.actor.side] ?? "—";
}

/** The reason code of an event, in words: a contact outcome or a closure reason. */
export function eventReasonLabel(event: FollowupEvent): string | null {
  if (!event.reason_code) return null;
  if (event.type === "CONTACT_RECORDED") return contactOutcomeLabels[event.reason_code as FollowupContactOutcome] ?? event.reason_code;
  if (event.type === "FOLLOWUP_CLOSED") return closeReasonLabels[event.reason_code as FollowupCloseReason] ?? event.reason_code;
  return null;
}

/**
 * The message's expiry has passed while the follow-up is still OPEN: it can
 * no longer be managed (FOLLOWUP_EXPIRED) and the system closes it EXPIRED.
 * The server's rule, not a new one; the server still decides.
 */
export function isPastMessageExpiry(followup: Pick<FollowupSummary, "status" | "message">, now = Date.now()): boolean {
  if (followup.status !== "OPEN" || !followup.message.expires_at) return false;
  const expires = Date.parse(followup.message.expires_at);
  return Number.isFinite(expires) && expires <= now;
}

/** Shown under every note field (ADR-06: the interface says so). */
export const NOTE_GUIDANCE =
  "ملاحظة داخلية قصيرة تظهر لمستخدمي المتابعة المخوّلين فقط، ولا تظهر للأسرة. لا تكتب فيها معلومات صحية أو أرقام هويات أو بيانات اتصال أو وثائق.";

export const BUSY_MESSAGE = "النظام مشغول حاليًا ولم يُحفظ شيء. أعد المحاولة بعد لحظات.";

const CODE_MESSAGES: Record<string, string> = {
  FOLLOWUP_NOT_FOUND: "هذه المتابعة غير متاحة.",
  FOLLOWUP_ACTOR_NOT_ALLOWED: "لا تملك صلاحية إدارة هذه المتابعة. يمكنك الاطلاع عليها فقط.",
  FOLLOWUP_NOT_OPEN: "أُغلقت هذه المتابعة بالفعل. حدّثنا الصفحة بحالتها الحالية.",
  FOLLOWUP_EXPIRED: "انتهت مدة الإعلان، ولم تعد المتابعة قابلة للإدارة. يغلقها النظام تلقائيًا.",
  FOLLOWUP_OWNERSHIP_CHANGED: "تغيّرت مسؤولية المتابعة أثناء التنفيذ ولم يُحفظ شيء. حدّثنا الصفحة؛ أعد المحاولة إن بقيت متاحة لك.",
  FOLLOWUP_CLIENT_REFERENCE_CONFLICT: "تعذّر حفظ التواصل لأن مرجعه استُخدم لتواصل مختلف. راجع البيانات وأرسل مجددًا.",
  FOLLOWUP_CLIENT_REFERENCE_INVALID: "تعذّر حفظ التواصل. أعد المحاولة.",
  FOLLOWUP_REASON_INVALID: "السبب المختار غير صالح.",
  FOLLOWUP_NOTE_INVALID: "الملاحظة الداخلية يجب ألا تتجاوز 1000 حرف.",
  FOLLOWUP_BUSY: BUSY_MESSAGE,
  COORDINATOR_SPACE_UNAVAILABLE: "مساحة التنسيق غير متاحة لهذا الحساب حاليًا.",
};

/** The stable code of an API refusal ({message, code}), if any. */
export function followupErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

/** No response at all: offline, or the connection dropped (the request may or may not have arrived). */
export function isConnectionError(error: unknown): boolean {
  return !(error instanceof ApiError);
}

/**
 * A safe Arabic message for a failed follow-up request. `write` adds what a
 * lost response means for that write: a contact may be re-sent as is (the
 * same reference is replayed, never duplicated); a closure is re-read first.
 */
export function followupErrorMessage(error: unknown, write?: "contact" | "close"): string {
  if (isConnectionError(error)) {
    const offline = typeof navigator !== "undefined" && navigator.onLine === false;
    const base = offline ? "أنت غير متصل بالإنترنت." : "تعذّر الاتصال بالخادم.";
    if (write === "contact") return `${base} قد لا يكون التواصل قد حُفظ. أعد الإرسال عند عودة الاتصال؛ لن يُسجَّل مرتين.`;
    if (write === "close") return `${base} قد لا يكون الإغلاق قد تم. حدّثنا حالة المتابعة؛ تحقق منها قبل إعادة المحاولة.`;
    return `${base} تحقق من الإنترنت وحاول مرة أخرى.`;
  }
  const code = followupErrorCode(error);
  if (code && CODE_MESSAGES[code]) return CODE_MESSAGES[code];
  switch ((error as ApiError).status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا تملك صلاحية تنفيذ هذا الإجراء.";
    case 404:
      return "هذه المتابعة غير متاحة.";
    case 409:
      return "تغيّرت حالة المتابعة. حدّثنا الصفحة بحالتها الحالية.";
    case 422:
      return "تحقق من البيانات المدخلة.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. انتظر دقيقة ثم حاول مجددًا.";
    case 503:
      return BUSY_MESSAGE;
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
