import type { StatusTone } from "@/components/shared/status-badge";
import type { ChangeRequestStatus, WorkflowEventType } from "@/lib/types/api/change-request";

// The Family Portal wording of Change Requests (PWA-5f). Codes come from the
// server; nothing here decides what is allowed. Type labels and rejection
// reasons are shared with the Staff workspace (lib/utils/change-request.ts).

export const familyRequestStatusLabels: Record<ChangeRequestStatus, string> = {
  DRAFT: "مسودة",
  SUBMITTED: "تم تقديم الطلب",
  UNDER_REVIEW: "قيد المراجعة",
  RETURNED_FOR_CLARIFICATION: "مطلوب استكمال معلومات",
  RESUBMITTED: "تم إرسال الاستكمال",
  // NOT the registry update: only APPLIED is.
  APPROVED: "معتمد بانتظار التطبيق",
  APPLIED: "تم تطبيق التعديل",
  REJECTED: "مرفوض",
  CANCELLED: "ملغى",
};

/** What the status means for the family, in plain words (the detail screen). */
export const familyRequestStatusDescriptions: Record<ChangeRequestStatus, string> = {
  DRAFT: "مسودة لم تُقدَّم.",
  SUBMITTED: "استلمنا طلبك، وهو بانتظار المراجعة.",
  UNDER_REVIEW: "يراجع فريق السجل طلبك الآن.",
  RETURNED_FOR_CLARIFICATION: "يحتاج فريق المراجعة إلى معلومات إضافية منك. أرسل الاستكمال لتتابع المراجعة.",
  RESUBMITTED: "أرسلت الاستكمال، والطلب بانتظار متابعة المراجعة.",
  APPROVED: "اعتُمد طلبك، لكن التعديل لم يُطبَّق على سجل أسرتك بعد.",
  APPLIED: "طُبّق التعديل على سجل أسرتك.",
  REJECTED: "لم تتم الموافقة على هذا الطلب. يمكنك تقديم طلب جديد إذا لزم.",
  CANCELLED: "ألغيت هذا الطلب، ولم يتغير سجل أسرتك.",
};

/** Only APPLIED reads as success. */
export const familyRequestStatusTones: Record<ChangeRequestStatus, StatusTone> = {
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

/** The statuses offered in the history filter (DRAFT is unused in V1). */
export const FAMILY_REQUEST_FILTER_STATUSES: ChangeRequestStatus[] = [
  "SUBMITTED",
  "UNDER_REVIEW",
  "RETURNED_FOR_CLARIFICATION",
  "RESUBMITTED",
  "APPROVED",
  "APPLIED",
  "REJECTED",
  "CANCELLED",
];

/** Timeline wording from the family's point of view. APPLY_FAILED is never shown. */
export const familyRequestEventLabels: Partial<Record<WorkflowEventType, string>> = {
  SUBMITTED: "قُدّم الطلب",
  REVIEW_STARTED: "بدأت مراجعة الطلب",
  RETURNED: "طُلب استكمال معلومات",
  RESUBMITTED: "أُرسل الاستكمال",
  APPROVED: "اعتُمد الطلب",
  REJECTED: "لم تتم الموافقة على الطلب",
  APPLIED: "طُبّق التعديل على سجل الأسرة",
  CANCELLED: "أُلغي الطلب",
};

/** Whose words a public message is, from the family's point of view. */
export function familyMessageLabel(event: WorkflowEventType): string {
  if (event === "RESUBMITTED") return "ردّك";
  if (event === "REJECTED") return "توضيح من فريق المراجعة";
  return "رسالة فريق المراجعة";
}
