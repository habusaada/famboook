import { z } from "zod";
import type { RegisterFamilyPayload } from "@/lib/types/api/family";

// UX validation only — kept light on purpose (docs/02-DATA-DICTIONARY.md
// §68: Zod supplements but never replaces Laravel's authoritative
// validation). Laravel's 422 response is the source of truth; this just
// catches empty-required-field mistakes before a round trip.
export const familyRegistrationSchema = z.object({
  registrationDate: z.string().min(1, "تاريخ التسجيل مطلوب"),
  registrationSource: z.enum([
    "PAPER_FORM",
    "MANUAL_ENTRY",
    "IMPORT",
    "VERIFIED_SOURCE",
  ]),
  paperFormNo: z.string().optional(),
  notes: z.string().optional(),

  headFullName: z.string().min(2, "اسم رب الأسرة مطلوب"),
  headNationalId: z.string().optional(),
  headGender: z.enum(["MALE", "FEMALE"]),
  headBirthDate: z.string().min(1, "تاريخ الميلاد مطلوب"),
  headMobile: z.string().optional(),

  governorate: z.string().min(1, "المحافظة مطلوبة"),
  city: z.string().min(1, "المدينة مطلوبة"),
  area: z.string().optional(),
  addressText: z.string().optional(),
  displacementStatus: z.string().optional(),
});

export type FamilyRegistrationValues = z.infer<typeof familyRegistrationSchema>;

// Maps the flat frontend form values to the canonical nested payload
// required by POST /api/v1/families (backend/app/Http/Requests/Api/V1/
// RegisterFamilyRequest.php). The backend contract is authoritative —
// this adapts the form to it, not the other way around.
export function toRegisterFamilyPayload(
  values: FamilyRegistrationValues
): RegisterFamilyPayload {
  return {
    registration_date: values.registrationDate,
    registration_source: values.registrationSource,
    paper_form_no: values.paperFormNo || null,
    notes: values.notes || null,
    household_head: {
      full_name: values.headFullName,
      national_id: values.headNationalId || null,
      gender: values.headGender,
      birth_date: values.headBirthDate,
      mobile: values.headMobile || null,
    },
    residence: {
      governorate: values.governorate,
      city: values.city,
      area: values.area || null,
      address_text: values.addressText || null,
      displacement_status: values.displacementStatus || null,
    },
  };
}

// Maps Laravel's 422 dot-notation field names (e.g.
// "household_head.full_name") back onto the flat RHF field names.
export const apiFieldToFormField: Record<string, keyof FamilyRegistrationValues> = {
  registration_date: "registrationDate",
  registration_source: "registrationSource",
  paper_form_no: "paperFormNo",
  notes: "notes",
  "household_head.full_name": "headFullName",
  "household_head.national_id": "headNationalId",
  "household_head.gender": "headGender",
  "household_head.birth_date": "headBirthDate",
  "household_head.mobile": "headMobile",
  "residence.governorate": "governorate",
  "residence.city": "city",
  "residence.area": "area",
  "residence.address_text": "addressText",
  "residence.displacement_status": "displacementStatus",
};
