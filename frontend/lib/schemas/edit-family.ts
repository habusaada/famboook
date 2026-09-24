import { z } from "zod";
import type { FamilyDetail, UpdateFamilyPayload } from "@/lib/types/api/family";

// UX validation only — Laravel's UpdateFamilyRequest is authoritative.
export const editFamilyRegistrationSchema = z.object({
  registrationDate: z.string().min(1, "تاريخ التسجيل مطلوب"),
  paperFormNo: z.string().max(255, "النص طويل جدًا").optional(),
  notes: z.string().optional(),
});

export type EditFamilyRegistrationValues = z.infer<typeof editFamilyRegistrationSchema>;

export function familyRegistrationFormValues(
  family: FamilyDetail
): EditFamilyRegistrationValues {
  return {
    registrationDate: family.registration_date ?? "",
    paperFormNo: family.paper_form_no ?? "",
    notes: family.notes ?? "",
  };
}

export function toUpdateFamilyPayload(
  values: EditFamilyRegistrationValues
): UpdateFamilyPayload {
  return {
    registration_date: values.registrationDate,
    paper_form_no: values.paperFormNo || null,
    notes: values.notes || null,
  };
}

export const familyApiFieldToFormField: Record<string, keyof EditFamilyRegistrationValues> = {
  registration_date: "registrationDate",
  paper_form_no: "paperFormNo",
  notes: "notes",
};
