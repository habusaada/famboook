import { z } from "zod";
import type { FamilyMember } from "@/lib/api/family-household";
import { toAsciiDigits } from "@/lib/schemas/family-auth";

// The household head's mobile CONTACT_UPDATE form (docs/11 FP-ADR-079).
// Laravel (ContactUpdateHandler, FamilyMobile) is the validation authority;
// these rules only spare a round trip. Primary mobile only.

export const CONTACT_MOBILE_INVALID = "رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.";

/** Arabic or Persian digits as ASCII; spaces, dashes and bidi marks removed. */
export function normalizeMobile(value: string): string {
  return toAsciiDigits(value).replace(/[\s‎‏؜\-.]+/g, "");
}

export const contactUpdateSchema = z
  .object({
    memberRef: z.string().refine((v) => v !== "", "اختر الفرد."),
    mobile: z.string().refine((v) => /^05[0-9]{8}$/.test(normalizeMobile(v)), CONTACT_MOBILE_INVALID),
    // The head's OWN number needs the current password (set when the head is chosen).
    self: z.boolean(),
    currentPassword: z.string(),
  })
  .superRefine((values, ctx) => {
    if (values.self && values.currentPassword === "") {
      ctx.addIssue({ code: "custom", path: ["currentPassword"], message: "أدخل كلمة المرور الحالية لتغيير رقم جوالك." });
    }
  });
export type ContactUpdateInput = z.input<typeof contactUpdateSchema>;
export type ContactUpdateValues = z.output<typeof contactUpdateSchema>;

export const CONTACT_UPDATE_DEFAULTS: ContactUpdateInput = { memberRef: "", mobile: "", self: false, currentPassword: "" };

/** Members whose number can be changed: available, named, not deceased. */
export function contactUpdateEligible(member: FamilyMember): boolean {
  return member.available && Boolean(member.full_name) && member.life_status !== "DECEASED";
}
