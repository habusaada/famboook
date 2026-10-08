import { z } from "zod";

// UX validation only — Laravel's ReturnChangeRequestRequest and
// RejectChangeRequestRequest (and the Domain Actions) are authoritative.
// The server also removes control and bidi characters before storing.

export const WORKFLOW_TEXT_MAX = 2000;

const text = z.string().max(WORKFLOW_TEXT_MAX, `النص أطول من ${WORKFLOW_TEXT_MAX} حرف.`);

export const returnChangeRequestSchema = z.object({
  publicMessage: text.refine((v) => v.trim().length > 0, "رسالة الاستيضاح للأسرة مطلوبة."),
  internalNote: text,
});
export type ReturnChangeRequestValues = z.infer<typeof returnChangeRequestSchema>;

export const REJECTION_REASONS = [
  "INSUFFICIENT_INFORMATION",
  "CANNOT_VERIFY",
  "DATA_ALREADY_CORRECT",
  "DUPLICATE_REQUEST",
  "DATA_CHANGED",
  "NO_LONGER_APPLICABLE",
  "OTHER",
] as const;

export const rejectChangeRequestSchema = z
  .object({
    reason: z.string(),
    publicMessage: text,
    internalNote: text,
  })
  .superRefine((values, ctx) => {
    if (!(REJECTION_REASONS as readonly string[]).includes(values.reason)) {
      ctx.addIssue({ code: "custom", path: ["reason"], message: "اختر سبب الرفض." });
    }
    if (values.reason === "OTHER" && values.publicMessage.trim() === "") {
      ctx.addIssue({ code: "custom", path: ["publicMessage"], message: "رسالة الرفض للأسرة مطلوبة لهذا السبب." });
    }
  });
export type RejectChangeRequestValues = z.infer<typeof rejectChangeRequestSchema>;

// The household head's reply to a clarification (PWA-5f). Laravel's
// ResubmitFamilyChangeRequestRequest is authoritative.
export const resubmitFamilyChangeRequestSchema = z.object({
  response: text.refine((v) => v.trim().length > 0, "اكتب ردّك على طلب الاستكمال."),
});
export type ResubmitFamilyChangeRequestValues = z.infer<typeof resubmitFamilyChangeRequestSchema>;
