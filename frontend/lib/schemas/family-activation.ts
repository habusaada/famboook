import { z } from "zod";

// Family activation forms (docs/11 §30a). UX only: the Laravel API is the
// authoritative validator and normalizer.

const ARABIC_INDIC = "٠١٢٣٤٥٦٧٨٩";
const PERSIAN = "۰۱۲۳۴۵۶۷۸۹";

/** Arabic-Indic and Persian digits as ASCII digits; everything else untouched. */
export function toAsciiDigits(value: string): string {
  return value.replace(/[٠-٩۰-۹]/g, (digit) => {
    const index = ARABIC_INDIC.indexOf(digit);
    return String(index >= 0 ? index : PERSIAN.indexOf(digit));
  });
}

/**
 * What the strict server normalizer removes: whitespace, the bidirectional
 * marks an RTL keyboard inserts, and the separators - . / _ . Letters stay,
 * so they fail the nine-digit check instead of being stripped away.
 */
export function normalizeNationalId(value: string): string {
  return toAsciiDigits(value).replace(/[\s‎‏؜\-./_]+/g, "");
}

export const NATIONAL_ID_INVALID = "رقم الهوية يجب أن يتكون من 9 أرقام.";

export const nationalIdSchema = z.object({
  national_id: z
    .string()
    .min(1, "رقم الهوية مطلوب.")
    .transform(normalizeNationalId)
    .pipe(z.string().regex(/^[0-9]{9}$/, NATIONAL_ID_INVALID)),
});
export type NationalIdInput = z.input<typeof nationalIdSchema>;
export type NationalIdValues = z.output<typeof nationalIdSchema>;

export const OTP_LENGTH = 6;

/** The digits of a typed or pasted code, at most six. */
export function normalizeOtp(value: string): string {
  return toAsciiDigits(value).replace(/[^0-9]/g, "").slice(0, OTP_LENGTH);
}

/** The approved Family password policy: a minimum length and nothing else. */
export const FAMILY_PASSWORD_MIN_LENGTH = 8;

export const passwordSchema = z
  .object({
    password: z
      .string()
      .min(1, "كلمة المرور مطلوبة.")
      .min(FAMILY_PASSWORD_MIN_LENGTH, `كلمة المرور يجب ألا تقل عن ${FAMILY_PASSWORD_MIN_LENGTH} أحرف.`)
      .max(255, "كلمة المرور طويلة جدًا."),
    password_confirmation: z.string().min(1, "تأكيد كلمة المرور مطلوب."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "كلمتا المرور غير متطابقتين.",
  });
export type PasswordValues = z.infer<typeof passwordSchema>;
