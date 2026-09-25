import { z } from "zod";
import type { FamilyDetail, UpdateFamilyPayload } from "@/lib/types/api/family";

// UX validation only — Laravel's UpdateFamilyRequest is authoritative.
export const editFamilyRegistrationSchema = z.object({
  registrationDate: z.string().min(1, "تاريخ التسجيل مطلوب"),
  paperFormNo: z.string().max(255, "النص طويل جدًا").optional(),
  notes: z.string().optional(),
  clanCode: z.string().min(1, "العشيرة / العائلة مطلوبة"),
  // "" = no Branch.
  branchCode: z.string(),
});

export type EditFamilyRegistrationValues = z.infer<typeof editFamilyRegistrationSchema>;

export function familyRegistrationFormValues(
  family: FamilyDetail
): EditFamilyRegistrationValues {
  return {
    registrationDate: family.registration_date ?? "",
    paperFormNo: family.paper_form_no ?? "",
    notes: family.notes ?? "",
    clanCode: family.clan?.code ?? "",
    branchCode: family.branch?.code ?? "",
  };
}

export function toUpdateFamilyPayload(
  values: EditFamilyRegistrationValues
): UpdateFamilyPayload {
  return {
    registration_date: values.registrationDate,
    paper_form_no: values.paperFormNo || null,
    notes: values.notes || null,
    // Always explicit, so a Clan change never keeps a stale Branch.
    clan_code: values.clanCode,
    branch_code: values.branchCode || null,
  };
}

export const familyApiFieldToFormField: Record<string, keyof EditFamilyRegistrationValues> = {
  registration_date: "registrationDate",
  paper_form_no: "paperFormNo",
  notes: "notes",
  clan_code: "clanCode",
  branch_code: "branchCode",
};
