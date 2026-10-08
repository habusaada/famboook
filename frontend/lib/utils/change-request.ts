import type { StatusTone } from "@/components/shared/status-badge";
import type {
  ChangeRequestAction,
  ChangeRequestApplyFailure,
  ChangeRequestRejectionReason,
  ChangeRequestStatus,
  ChangeRequestType,
  WorkflowActorSide,
  WorkflowEventType,
} from "@/lib/types/api/change-request";

// Arabic presentation of the Change Request machine codes (PWA-5d). The
// backend stores codes only; nothing here decides what is allowed.

export const changeRequestStatusLabels: Record<ChangeRequestStatus, string> = {
  DRAFT: "مسودة",
  SUBMITTED: "مقدّم",
  UNDER_REVIEW: "قيد المراجعة",
  RETURNED_FOR_CLARIFICATION: "مُعاد للاستكمال",
  RESUBMITTED: "أُعيد تقديمه",
  // APPROVED is NOT completed: nothing in the registry has changed yet.
  APPROVED: "معتمد بانتظار التطبيق",
  APPLIED: "مطبّق",
  REJECTED: "مرفوض",
  CANCELLED: "ملغى",
};

/** Only APPLIED reads as success: it is the completed registry update. */
export const changeRequestStatusTones: Record<ChangeRequestStatus, StatusTone> = {
  DRAFT: "neutral",
  SUBMITTED: "info",
  UNDER_REVIEW: "brand",
  RETURNED_FOR_CLARIFICATION: "warning",
  RESUBMITTED: "info",
  APPROVED: "warning",
  APPLIED: "success",
  REJECTED: "danger",
  CANCELLED: "neutral",
};

/** The statuses offered in the queue filter (DRAFT is unused in V1). */
export const CHANGE_REQUEST_FILTER_STATUSES: ChangeRequestStatus[] = [
  "SUBMITTED",
  "UNDER_REVIEW",
  "RETURNED_FOR_CLARIFICATION",
  "RESUBMITTED",
  "APPROVED",
  "APPLIED",
  "REJECTED",
  "CANCELLED",
];

export const changeRequestTypeLabels: Record<ChangeRequestType, string> = {
  CONTACT_UPDATE: "تحديث بيانات التواصل",
  RESIDENCE_UPDATE: "تحديث بيانات السكن",
  PERSON_CORRECTION: "تصحيح بيانات فرد",
  ADD_FAMILY_MEMBER: "إضافة فرد إلى الأسرة",
  MEMBERSHIP_CHANGE: "تغيير عضوية فرد",
  HOUSEHOLD_HEAD_CHANGE: "تغيير رب الأسرة",
  BIRTH_REPORT: "تسجيل مولود",
  DEATH_REPORT: "الإبلاغ عن وفاة",
  MARRIAGE_UPDATE: "تحديث الحالة الزوجية",
  DOCUMENT_UPDATE: "تحديث وثيقة",
  OTHER: "طلب آخر",
};

export const workflowEventLabels: Record<WorkflowEventType, string> = {
  SUBMITTED: "قدّمت الأسرة الطلب",
  REVIEW_STARTED: "بدأت المراجعة",
  RETURNED: "أُعيد الطلب للاستكمال",
  RESUBMITTED: "ردّت الأسرة وأعادت تقديم الطلب",
  APPROVED: "اعتُمد الطلب",
  REJECTED: "رُفض الطلب",
  APPLIED: "طُبّق التعديل على سجل الأسرة",
  APPLY_FAILED: "تعذّر تطبيق التعديل",
  CANCELLED: "ألغت الأسرة الطلب",
};

export const workflowEventTones: Record<WorkflowEventType, StatusTone> = {
  SUBMITTED: "info",
  REVIEW_STARTED: "brand",
  RETURNED: "warning",
  RESUBMITTED: "info",
  APPROVED: "warning",
  REJECTED: "danger",
  APPLIED: "success",
  APPLY_FAILED: "danger",
  CANCELLED: "neutral",
};

export const actorSideLabels: Record<WorkflowActorSide, string> = {
  FAMILY: "الأسرة",
  STAFF: "الموظفون",
  SYSTEM: "النظام",
};

export const rejectionReasonLabels: Record<ChangeRequestRejectionReason, string> = {
  INSUFFICIENT_INFORMATION: "المعلومات المقدمة غير كافية",
  CANNOT_VERIFY: "تعذّر التحقق من صحة البيانات المطلوبة",
  DATA_ALREADY_CORRECT: "البيانات المسجلة صحيحة",
  DUPLICATE_REQUEST: "يوجد طلب آخر بنفس المضمون",
  DATA_CHANGED: "تغيّرت البيانات المسجلة بعد تقديم الطلب",
  NO_LONGER_APPLICABLE: "لم يعد الطلب قابلًا للتنفيذ",
  OTHER: "سبب آخر",
};

export const applyFailureLabels: Record<ChangeRequestApplyFailure, string> = {
  BASE_CHANGED: "تغيّرت البيانات المسجلة بعد تقديم الطلب",
  PRECONDITION_FAILED: "لم تعد شروط تنفيذ الطلب متحققة",
  NOT_APPLICABLE: "لم يعد الطلب قابلًا للتنفيذ",
  APPLY_FAILED: "خطأ غير متوقع أثناء التطبيق",
};

/** A reason code as text: a rejection reason or an apply failure; unknown codes are not shown raw. */
export function reasonCodeLabel(eventType: WorkflowEventType, code: string | null): string | null {
  if (!code) return null;
  if (eventType === "APPLY_FAILED") return applyFailureLabels[code as ChangeRequestApplyFailure] ?? "سبب غير معروف";
  if (eventType === "REJECTED") return rejectionReasonLabels[code as ChangeRequestRejectionReason] ?? "سبب غير معروف";
  return null;
}

export function changeRequestActionLabel(action: ChangeRequestAction, retry = false): string {
  switch (action) {
    case "start_review":
      return "بدء المراجعة";
    case "return":
      return "إرجاع للاستكمال";
    case "approve":
      return "اعتماد الطلب";
    case "reject":
      return "رفض الطلب";
    case "apply":
      return retry ? "إعادة محاولة التطبيق" : "تطبيق التعديل";
  }
}

/** The permission each action needs (docs/06 AUTH-ADR-089) — a UI guard only. */
export const changeRequestActionPermissions: Record<ChangeRequestAction, string> = {
  start_review: "change-request.review",
  return: "change-request.return",
  approve: "change-request.approve",
  reject: "change-request.reject",
  apply: "change-request.apply",
};

export const changeRequestsNoun = (n: number) => (n === 1 ? "طلب" : n === 2 ? "طلبان" : n <= 10 && n > 2 ? "طلبات" : "طلبًا");
