import { z } from "zod";

// UX validation only (docs/02-DATA-DICTIONARY.md §68: Zod supplements
// but never replaces Laravel's authoritative validation). Field names
// map to docs/02 §6 (families), §9 (persons), §19 (family_residences).
export const familyRegistrationSchema = z.object({
  // Basic family info
  registrationDate: z.string().min(1, "تاريخ التسجيل مطلوب"),
  registrationSource: z.enum([
    "PAPER_FORM",
    "MANUAL_ENTRY",
    "IMPORT",
    "VERIFIED_SOURCE",
  ]),
  paperFormNo: z.string().optional(),
  notes: z.string().optional(),

  // Household head
  headFullName: z.string().min(2, "اسم رب الأسرة مطلوب"),
  headNationalId: z.string().optional(),
  headGender: z.enum(["MALE", "FEMALE"]),
  headBirthDate: z.string().min(1, "تاريخ الميلاد مطلوب"),
  headMobile: z.string().optional(),

  // Current residence
  governorate: z.string().min(1, "المحافظة مطلوبة"),
  city: z.string().min(1, "المدينة مطلوبة"),
  area: z.string().optional(),
  addressText: z.string().optional(),
  displacementStatus: z.string().optional(),
});

export type FamilyRegistrationValues = z.infer<typeof familyRegistrationSchema>;
