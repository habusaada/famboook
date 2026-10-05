import type { RegistrationSource } from "@/lib/types/api/family";

// docs/02 §7 registration_source; the same values describe the source of a
// household declaration (docs/02 §20a).
export const registrationSourceLabels: Record<RegistrationSource, string> = {
  PAPER_FORM: "نموذج ورقي",
  MANUAL_ENTRY: "إدخال يدوي",
  IMPORT: "استيراد بيانات",
  VERIFIED_SOURCE: "مصدر موثّق",
};
