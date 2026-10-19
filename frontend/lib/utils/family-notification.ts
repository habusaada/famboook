import { ApiError } from "@/lib/api/client";
import { apiErrorCode } from "@/lib/api/family-notifications";

// The Family Portal wording of notifications (FB-NOTIFY-01C.2). Codes and
// states come from the server; nothing here decides what is allowed.

/** The bell's badge: the count, or 9+ above nine. */
export function unreadBadgeText(count: number): string {
  return count > 9 ? "9+" : String(count);
}

/** The bell's accessible name, with the unread count when there is one. */
export function bellLabel(count: number | undefined): string {
  if (!count) return "الإشعارات";
  return count > 9 ? "الإشعارات، أكثر من 9 غير مقروءة" : `الإشعارات، ${count} غير مقروءة`;
}

/**
 * An action_path the portal may navigate to: a family-relative path of plain
 * segments only (/family/…). Anything else — another origin, a scheme, //,
 * a backslash, a query or a fragment — renders no link.
 */
export function safeActionPath(path: string | null | undefined): string | null {
  if (typeof path !== "string") return null;
  return /^\/family(?:\/[A-Za-z0-9._~-]+)*\/?$/.test(path) && !path.includes("..") ? path : null;
}

export const RATE_LIMIT_MESSAGE = "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";

/** True when the server refused because of the per-user rate limit. */
export function isRateLimited(error: unknown): boolean {
  return error instanceof ApiError && error.status === 429;
}

/** A safe message for a failed notification action (never the server's internals). */
export function familyNotificationErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.";
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return apiErrorCode(error) === "NOTIFICATION_ACTOR_NOT_ALLOWED"
        ? "لا يمكن تنفيذ هذا الإجراء على الإشعار من هذا الحساب."
        : "لا يمكنك تنفيذ هذا الإجراء.";
    case 404:
      return "هذا الإشعار غير متاح.";
    case 409:
      return "تغيّرت حالة الإشعار. حدّثنا الصفحة بحالته الحالية.";
    case 429:
      return RATE_LIMIT_MESSAGE;
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
