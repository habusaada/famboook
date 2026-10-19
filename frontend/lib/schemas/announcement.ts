import { z } from "zod";
import { ANNOUNCEMENT_MAX_FAMILIES, type PublicAudience } from "@/lib/api/announcements";

// UX validation only — Laravel (StoreAnnouncementRequest,
// NotificationAudienceReferences, CreateAnnouncementDraftAction) is
// authoritative: trimmed lengths, the audience grammar, eligibility, scope
// and send-all.

export const ANNOUNCEMENT_TITLE_MAX = 150;
export const ANNOUNCEMENT_BODY_MAX = 2000;
/** R2: publication needs expires_at at least one hour ahead. */
export const ANNOUNCEMENT_EXPIRY_LEAD_MS = 60 * 60 * 1000;

export const staffAudienceTypes = ["STAFF_FAMILIES", "STAFF_ALL_ACTIVE"] as const;

export const staffAnnouncementSchema = z
  .object({
    title: z.string().trim().min(1, "عنوان الإعلان مطلوب").max(ANNOUNCEMENT_TITLE_MAX, `العنوان أطول من ${ANNOUNCEMENT_TITLE_MAX} حرفًا`),
    body: z.string().trim().min(1, "نص الإعلان مطلوب").max(ANNOUNCEMENT_BODY_MAX, `النص أطول من ${ANNOUNCEMENT_BODY_MAX} حرف`),
    level: z.enum(["NORMAL", "IMPORTANT"]),
    /** A datetime-local value ("" = no expiry). */
    expiresAt: z.string(),
    audienceType: z.enum(staffAudienceTypes),
    familyCodes: z.array(z.string()),
  })
  .superRefine((values, ctx) => {
    if (values.expiresAt) {
      const at = Date.parse(values.expiresAt);
      if (!Number.isFinite(at)) {
        ctx.addIssue({ code: "custom", path: ["expiresAt"], message: "موعد الانتهاء غير صالح" });
      } else if (at < Date.now() + ANNOUNCEMENT_EXPIRY_LEAD_MS) {
        ctx.addIssue({ code: "custom", path: ["expiresAt"], message: "يجب أن يكون موعد الانتهاء بعد ساعة على الأقل" });
      }
    }
    if (values.audienceType === "STAFF_FAMILIES") {
      const distinct = new Set(values.familyCodes.map((c) => c.trim()).filter(Boolean));
      if (distinct.size === 0) ctx.addIssue({ code: "custom", path: ["familyCodes"], message: "اختر أسرة واحدة على الأقل" });
      if (distinct.size > ANNOUNCEMENT_MAX_FAMILIES) {
        ctx.addIssue({ code: "custom", path: ["familyCodes"], message: `الحد الأقصى ${ANNOUNCEMENT_MAX_FAMILIES} أسرة` });
      }
    }
  });

export type StaffAnnouncementValues = z.infer<typeof staffAnnouncementSchema>;

/** The PUBLIC audience for the current selection: distinct trimmed family codes, sorted. */
export function staffAudience(values: Pick<StaffAnnouncementValues, "audienceType" | "familyCodes">): PublicAudience {
  if (values.audienceType === "STAFF_ALL_ACTIVE") return { type: "STAFF_ALL_ACTIVE" };
  const codes = [...new Set(values.familyCodes.map((c) => c.trim()).filter(Boolean))].sort();
  return { type: "STAFF_FAMILIES", family_codes: codes };
}

/** A stable key of an audience: the preview it was counted for must match the one saved. */
export function audienceKey(audience: PublicAudience): string {
  return JSON.stringify(audience);
}

/** A datetime-local value as an ISO-8601 instant, or null. */
export function expiryInstant(value: string): string | null {
  if (!value) return null;
  const at = Date.parse(value);
  return Number.isFinite(at) ? new Date(at).toISOString() : null;
}
