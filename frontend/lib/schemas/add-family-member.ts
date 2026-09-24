import { z } from "zod";
import type { AddFamilyMemberPayload } from "@/lib/types/api/family";

// UX validation only — light on purpose (Laravel's 422 response is
// authoritative). Mirrors AddFamilyMemberRequest's shape exactly.
export const addFamilyMemberSchema = z.object({
  relationshipTypeId: z.number({
    message: "صلة القرابة مطلوبة",
  }),
  fullName: z.string().min(2, "الاسم الكامل مطلوب"),
  nationalId: z.string().optional(),
  gender: z.enum(["MALE", "FEMALE"]),
  maritalStatus: z.enum(["SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"]),
  birthDate: z.string().min(1, "تاريخ الميلاد مطلوب"),
  mobile: z.string().optional(),
  alternateMobile: z.string().optional(),
});

export type AddFamilyMemberValues = z.infer<typeof addFamilyMemberSchema>;

export function toAddFamilyMemberPayload(
  values: AddFamilyMemberValues
): AddFamilyMemberPayload {
  return {
    full_name: values.fullName,
    national_id: values.nationalId || null,
    gender: values.gender,
    marital_status: values.maritalStatus,
    birth_date: values.birthDate,
    mobile: values.mobile || null,
    alternate_mobile: values.alternateMobile || null,
    relationship_type_id: values.relationshipTypeId,
  };
}

export const memberApiFieldToFormField: Record<
  string,
  keyof AddFamilyMemberValues
> = {
  full_name: "fullName",
  national_id: "nationalId",
  gender: "gender",
  marital_status: "maritalStatus",
  birth_date: "birthDate",
  mobile: "mobile",
  alternate_mobile: "alternateMobile",
  relationship_type_id: "relationshipTypeId",
};

// docs task F: only where unambiguous and safe. The canonical
// relationship_types list has a single gender-neutral SPOUSE code (no
// separate husband/wife codes), so no default applies to it.
export const relationshipGenderDefault: Record<string, "MALE" | "FEMALE"> = {
  SON: "MALE",
  DAUGHTER: "FEMALE",
  FATHER: "MALE",
  MOTHER: "FEMALE",
};
