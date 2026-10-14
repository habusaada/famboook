import type { ChangeRequestStatus, ChangeRequestType } from "@/lib/types/api/change-request";

// The Coordinator Space wording of Change Requests (CO-02.6b). Codes come
// from the server; nothing here decides what is allowed. Status labels and
// tones are shared with the Family Portal (lib/utils/family-change-request.ts);
// what a status MEANS is worded here for the Coordinator who proposed it.

export const coordinatorRequestTypeLabels: Partial<Record<ChangeRequestType, string>> = {
  ADD_FAMILY_MEMBER: "إضافة فرد إلى أسرة",
  REGISTER_FAMILY: "تسجيل أسرة جديدة",
};

export function coordinatorRequestTypeLabel(type: ChangeRequestType): string {
  return coordinatorRequestTypeLabels[type] ?? "طلب تنسيق";
}

/** What the status means for the Coordinator, in plain words (the detail screen). */
export const coordinatorRequestStatusDescriptions: Record<ChangeRequestStatus, string> = {
  DRAFT: "مسودة لم تُقدَّم.",
  SUBMITTED: "استُلم طلبك، وهو بانتظار مراجعة فريق السجل.",
  UNDER_REVIEW: "يراجع فريق السجل طلبك الآن.",
  RETURNED_FOR_CLARIFICATION: "يحتاج فريق المراجعة إلى معلومات إضافية منك. أرسل الاستكمال لتتابع المراجعة.",
  RESUBMITTED: "أرسلت الاستكمال، والطلب بانتظار متابعة المراجعة.",
  APPROVED: "اعتُمد الطلب، لكنه لم يُطبَّق على السجل بعد.",
  APPLIED: "طُبّق الطلب على السجل.",
  REJECTED: "لم تتم الموافقة على هذا الطلب. يمكنك تقديم طلب جديد إذا لزم.",
  CANCELLED: "ألغيت هذا الطلب، ولم يتغير شيء في السجل.",
};

/** The statuses that count as «in progress» for the Coordinator's overview. */
export const COORDINATOR_OPEN_STATUSES: ChangeRequestStatus[] = ["SUBMITTED", "UNDER_REVIEW", "RESUBMITTED", "APPROVED"];
