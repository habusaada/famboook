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
  headMaritalStatus: z.enum(["SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"]),
  headBirthDate: z.string().min(1, "تاريخ الميلاد مطلوب"),
  headMobile: z.string().optional(),
  headAlternateMobile: z.string().optional(),
  headAlternateMobileOwnerRelation: z.string().optional(),

  governorate: z.string().min(1, "المحافظة مطلوبة"),
  city: z.string().min(1, "المدينة مطلوبة"),
  area: z.string().optional(),
  neighborhood: z.string().optional(),
  addressText: z.string().optional(),
  // Residence before displacement (not birthplace).
  originalResidenceText: z.string().optional(),
  // Unanswered = not collected (null), never silently "NO".
  isDisplaced: z.enum(["YES", "NO"]).optional(),
  displacementLocationText: z.string().optional(),
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
      marital_status: values.headMaritalStatus,
      birth_date: values.headBirthDate,
      mobile: values.headMobile || null,
      alternate_mobile: values.headAlternateMobile || null,
      // Only meaningful alongside an alternate number.
      alternate_mobile_owner_relation: values.headAlternateMobile
        ? values.headAlternateMobileOwnerRelation || null
        : null,
    },
    residence: {
      governorate: values.governorate,
      city: values.city,
      area: values.area || null,
      neighborhood: values.neighborhood || null,
      address_text: values.addressText || null,
      original_residence_text: values.originalResidenceText || null,
      displacement_status:
        values.isDisplaced === "YES"
          ? "DISPLACED"
          : values.isDisplaced === "NO"
            ? "NOT_DISPLACED"
            : null,
      // A displacement location only exists for a displaced family.
      displacement_location_text:
        values.isDisplaced === "YES"
          ? values.displacementLocationText || null
          : null,
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
  "household_head.marital_status": "headMaritalStatus",
  "household_head.birth_date": "headBirthDate",
  "household_head.mobile": "headMobile",
  "household_head.alternate_mobile": "headAlternateMobile",
  "household_head.alternate_mobile_owner_relation": "headAlternateMobileOwnerRelation",
  "residence.governorate": "governorate",
  "residence.city": "city",
  "residence.area": "area",
  "residence.neighborhood": "neighborhood",
  "residence.address_text": "addressText",
  "residence.original_residence_text": "originalResidenceText",
  "residence.displacement_status": "isDisplaced",
  "residence.displacement_location_text": "displacementLocationText",
};
