import { z } from "zod";
import { FOLLOWUP_CLOSE_REASONS, FOLLOWUP_CONTACT_OUTCOMES, FOLLOWUP_NOTE_MAX } from "@/lib/api/followups";

// UX validation only — Laravel (FollowupContactRequest / FollowupCloseRequest
// and the Domain Actions) is authoritative: the reason catalogues, the note
// length and the state, scope and ownership checks.

const note = z.string().max(FOLLOWUP_NOTE_MAX, `الملاحظة أطول من ${FOLLOWUP_NOTE_MAX} حرف`);

export const followupContactSchema = z.object({
  outcome: z.enum(FOLLOWUP_CONTACT_OUTCOMES as [string, ...string[]], { message: "اختر نتيجة التواصل" }),
  note,
});

export const followupCloseSchema = z.object({
  reason: z.enum(FOLLOWUP_CLOSE_REASONS as [string, ...string[]], { message: "اختر سبب الإغلاق" }),
  note,
});

export type FollowupContactFormValues = { outcome: string; note: string };
export type FollowupCloseFormValues = { reason: string; note: string };

/** A trimmed note, or none (the server also turns a blank note into none). */
export function noteValue(value: string): string | null {
  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : null;
}
