import { z } from "zod";
import { ANNOUNCEMENT_MAX_FAMILIES, type AnnouncementAudienceType, type PublicAudience } from "@/lib/api/announcements";

// UX validation only — Laravel (StoreAnnouncementRequest,
// NotificationAudienceReferences, CreateAnnouncementDraftAction) is
// authoritative: trimmed lengths, the audience grammar, eligibility, scope
// and send-all. One schema for both sides; each side passes its own
// audience types and the one that selects families by code.

export const ANNOUNCEMENT_TITLE_MAX = 150;
export const ANNOUNCEMENT_BODY_MAX = 2000;
/** R2: publication needs expires_at at least one hour ahead. */
export const ANNOUNCEMENT_EXPIRY_LEAD_MS = 60 * 60 * 1000;

/** A side's audience options: its own types only, and the one that takes family_codes. */
export type AudienceGrammar = {
  types: readonly AnnouncementAudienceType[];
  selection: AnnouncementAudienceType;
};

export const STAFF_AUDIENCES: AudienceGrammar = { types: ["STAFF_FAMILIES", "STAFF_ALL_ACTIVE"], selection: "STAFF_FAMILIES" };
export const COORDINATOR_AUDIENCES: AudienceGrammar = { types: ["COORDINATOR_SCOPE", "COORDINATOR_SELECTION"], selection: "COORDINATOR_SELECTION" };

export type AnnouncementFormValues = {
  title: string;
  body: string;
  level: "NORMAL" | "IMPORTANT";
  /** A datetime-local value ("" = no expiry). */
  expiresAt: string;
  audienceType: AnnouncementAudienceType;
  familyCodes: string[];
};

export function announcementSchema(grammar: AudienceGrammar) {
  return z
    .object({
      title: z.string().trim().min(1, "عنوان الإعلان مطلوب").max(ANNOUNCEMENT_TITLE_MAX, `العنوان أطول من ${ANNOUNCEMENT_TITLE_MAX} حرفًا`),
      body: z.string().trim().min(1, "نص الإعلان مطلوب").max(ANNOUNCEMENT_BODY_MAX, `النص أطول من ${ANNOUNCEMENT_BODY_MAX} حرف`),
      level: z.enum(["NORMAL", "IMPORTANT"]),
      expiresAt: z.string(),
      audienceType: z
        .enum(["STAFF_FAMILIES", "STAFF_ALL_ACTIVE", "COORDINATOR_SCOPE", "COORDINATOR_SELECTION"])
        .refine((t) => grammar.types.includes(t), "نوع المستلمين غير صالح"),
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
      if (values.audienceType === grammar.selection) {
        const distinct = new Set(values.familyCodes.map((c) => c.trim()).filter(Boolean));
        if (distinct.size === 0) ctx.addIssue({ code: "custom", path: ["familyCodes"], message: "اختر أسرة واحدة على الأقل" });
        if (distinct.size > ANNOUNCEMENT_MAX_FAMILIES) {
          ctx.addIssue({ code: "custom", path: ["familyCodes"], message: `الحد الأقصى ${ANNOUNCEMENT_MAX_FAMILIES} أسرة` });
        }
      }
    });
}

/**
 * The PUBLIC audience for the current selection: the selection type carries
 * distinct trimmed family codes, sorted; any other type carries nothing.
 */
export function formAudience(values: Pick<AnnouncementFormValues, "audienceType" | "familyCodes">, grammar: AudienceGrammar): PublicAudience {
  if (values.audienceType !== grammar.selection) return { type: values.audienceType } as PublicAudience;
  const codes = [...new Set(values.familyCodes.map((c) => c.trim()).filter(Boolean))].sort();
  return { type: grammar.selection, family_codes: codes } as PublicAudience;
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
