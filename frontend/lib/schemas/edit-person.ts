import { z } from "zod";
import type { UpdatePersonPayload } from "@/lib/types/api/person";

export const editPersonSchema = z.object({
  fullName: z.string().min(2, "الاسم الكامل مطلوب"),
  gender: z.enum(["MALE", "FEMALE"]),
  birthDate: z.string().optional(),
  mobile: z.string().optional(),
  alternateMobile: z.string().optional(),
  alternateMobileOwnerRelation: z.string().max(255, "النص طويل جدًا").optional(),
});

export type EditPersonValues = z.infer<typeof editPersonSchema>;

export function toUpdatePersonPayload(values: EditPersonValues): UpdatePersonPayload {
  return {
    full_name: values.fullName,
    gender: values.gender,
    birth_date: values.birthDate || null,
    mobile: values.mobile || null,
    alternate_mobile: values.alternateMobile || null,
    // Only meaningful alongside an alternate number.
    alternate_mobile_owner_relation: values.alternateMobile
      ? values.alternateMobileOwnerRelation || null
      : null,
  };
}

export const personApiFieldToFormField: Record<string, keyof EditPersonValues> = {
  full_name: "fullName",
  gender: "gender",
  birth_date: "birthDate",
  mobile: "mobile",
  alternate_mobile: "alternateMobile",
  alternate_mobile_owner_relation: "alternateMobileOwnerRelation",
};
