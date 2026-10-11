import { z } from "zod";
import type { FamilyMember } from "@/lib/api/family-household";

// The household head's DEATH_REPORT form: reports the death of a household
// member (never the head, never someone already deceased). The family gives
// the date of death OR states that it is unknown — never an invented date.
// Laravel (DeathReportHandler, the DeathDate rules of RecordPersonDeathAction)
// is the validation authority; these rules only spare a round trip.

const today = () => new Date().toISOString().slice(0, 10);

export const deathReportSchema = z
  .object({
    memberRef: z.string().refine((v) => v !== "", "اختر الفرد المتوفى."),
    deathDate: z
      .string()
      .refine((v) => v === "" || /^\d{4}-\d{2}-\d{2}$/.test(v), "تاريخ الوفاة غير صالح.")
      .refine((v) => v === "" || v <= today(), "تاريخ الوفاة لا يمكن أن يكون في المستقبل."),
    deathDateUnknown: z.boolean(),
    reason: z.string().max(2000, "النص طويل جدًا."),
  })
  .superRefine((values, ctx) => {
    if (!values.deathDateUnknown && values.deathDate === "") {
      ctx.addIssue({ code: "custom", path: ["deathDate"], message: "أدخل تاريخ الوفاة، أو اختر «تاريخ الوفاة غير معروف»." });
    }
  });
export type DeathReportInput = z.input<typeof deathReportSchema>;
export type DeathReportValues = z.output<typeof deathReportSchema>;

export const DEATH_REPORT_DEFAULTS: DeathReportInput = {
  memberRef: "",
  deathDate: "",
  deathDateUnknown: false,
  reason: "",
};

/** Members a death may be reported for: available, named, not the head, not already deceased. */
export function deathReportEligible(member: FamilyMember): boolean {
  return member.available && Boolean(member.full_name) && !member.is_household_head && member.life_status !== "DECEASED";
}

/** The `data` of POST /api/v1/family/change-requests for DEATH_REPORT. */
export function toDeathReportData(values: DeathReportInput) {
  return {
    member_ref: values.memberRef,
    death_date: values.deathDateUnknown || values.deathDate === "" ? null : values.deathDate,
    death_date_unknown: values.deathDateUnknown,
  };
}

/** Server field (data key) → form field. */
export const DEATH_REPORT_FIELDS: Record<string, keyof DeathReportInput> = {
  member_ref: "memberRef",
  death_date: "deathDate",
  death_date_unknown: "deathDateUnknown",
  reason: "reason",
};
