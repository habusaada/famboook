import { z } from "zod";
import type { FamilyMember } from "@/lib/api/family-household";

// The household head's PERSON_CORRECTION form: corrects a member's full name,
// birth date, gender and marital status. Laravel (PersonCorrectionHandler,
// the rules of the Staff edit) is the validation authority; these rules only
// spare a round trip. National ID and mobiles are not corrected here.

export const personCorrectionSchema = z.object({
  memberRef: z.string().refine((v) => v !== "", "اختر الفرد المطلوب تصحيح بياناته."),
  fullName: z.string().refine((v) => v.trim().length > 0, "الاسم الكامل مطلوب.").refine((v) => v.trim().length <= 255, "الاسم طويل جدًا."),
  birthDate: z
    .string()
    .refine((v) => v === "" || /^\d{4}-\d{2}-\d{2}$/.test(v), "تاريخ غير صالح.")
    .refine((v) => v === "" || v <= new Date().toISOString().slice(0, 10), "لا يمكن أن يكون تاريخ الميلاد في المستقبل."),
  gender: z.enum(["", "MALE", "FEMALE"]).refine((v) => v !== "", "اختر الجنس."),
  maritalStatus: z.enum(["", "SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"]).refine((v) => v !== "", "اختر الحالة الاجتماعية."),
  reason: z.string().max(2000, "النص طويل جدًا."),
});
export type PersonCorrectionInput = z.input<typeof personCorrectionSchema>;
export type PersonCorrectionValues = z.output<typeof personCorrectionSchema>;

export const PERSON_CORRECTION_DEFAULTS: PersonCorrectionInput = {
  memberRef: "",
  fullName: "",
  birthDate: "",
  gender: "",
  maritalStatus: "",
  reason: "",
};

/** The member's current values, as the form's starting point. */
export function personCorrectionFormValues(member: FamilyMember): PersonCorrectionInput {
  return {
    memberRef: member.member_ref,
    fullName: member.full_name ?? "",
    birthDate: member.birth_date ?? "",
    gender: member.gender ?? "",
    maritalStatus: member.marital_status ?? "",
    reason: "",
  };
}

/** The `data` of POST /api/v1/family/change-requests for PERSON_CORRECTION. */
export function toPersonCorrectionData(values: PersonCorrectionInput) {
  return {
    member_ref: values.memberRef,
    full_name: values.fullName.trim(),
    birth_date: values.birthDate === "" ? null : values.birthDate,
    gender: values.gender,
    marital_status: values.maritalStatus,
  };
}

/** Did the head change anything for this member? (The server refuses an unchanged proposal too.) */
export function personCorrectionChanges(member: FamilyMember, values: PersonCorrectionInput): boolean {
  const before = toPersonCorrectionData(personCorrectionFormValues(member));
  const after = toPersonCorrectionData(values);
  return (["full_name", "birth_date", "gender", "marital_status"] as const).some((key) => before[key] !== after[key]);
}

/** Server field (data key) → form field. */
export const PERSON_CORRECTION_FIELDS: Record<string, keyof PersonCorrectionInput> = {
  member_ref: "memberRef",
  full_name: "fullName",
  birth_date: "birthDate",
  gender: "gender",
  marital_status: "maritalStatus",
  reason: "reason",
};
