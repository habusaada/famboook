import { z } from "zod";
import type { AddFamilyMemberPayload } from "@/lib/types/api/family";

// UX validation only — light on purpose (Laravel's 422 response is
// authoritative). Mirrors AddFamilyMemberRequest's shape exactly.
export const addFamilyMemberSchema = z.object({
  fullName: z.string().min(2, "الاسم الكامل مطلوب"),
  nationalId: z.string().optional(),
  gender: z.enum(["MALE", "FEMALE"]),
  birthDate: z.string().min(1, "تاريخ الميلاد مطلوب"),
  mobile: z.string().optional(),
});

export type AddFamilyMemberValues = z.infer<typeof addFamilyMemberSchema>;

export function toAddFamilyMemberPayload(
  values: AddFamilyMemberValues
): AddFamilyMemberPayload {
  return {
    full_name: values.fullName,
    national_id: values.nationalId || null,
    gender: values.gender,
    birth_date: values.birthDate,
    mobile: values.mobile || null,
  };
}

export const memberApiFieldToFormField: Record<
  string,
  keyof AddFamilyMemberValues
> = {
  full_name: "fullName",
  national_id: "nationalId",
  gender: "gender",
  birth_date: "birthDate",
  mobile: "mobile",
};
