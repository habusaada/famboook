import { z } from "zod";

// Family authentication forms — activation, login, password reset
// (docs/11 §30a). UX only: the Laravel API is the
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

/**
 * The approved Family password policy: a minimum length, a confirmation and
 * no composition rule. One technical ceiling: bcrypt reads at most 72 BYTES,
 * so a longer password is refused — never truncated. It is bytes of UTF-8
 * (an Arabic letter is two), which is why the message does not quote a
 * number of characters.
 */
export const FAMILY_PASSWORD_MIN_LENGTH = 8;
export const FAMILY_PASSWORD_MAX_BYTES = 72;
export const PASSWORD_TOO_LONG = "كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.";

export function passwordByteLength(value: string): number {
  return new TextEncoder().encode(value).length;
}

export const passwordSchema = z
  .object({
    password: z
      .string()
      .min(1, "كلمة المرور مطلوبة.")
      .min(FAMILY_PASSWORD_MIN_LENGTH, `كلمة المرور يجب ألا تقل عن ${FAMILY_PASSWORD_MIN_LENGTH} أحرف.`)
      .refine((value) => passwordByteLength(value) <= FAMILY_PASSWORD_MAX_BYTES, PASSWORD_TOO_LONG),
    password_confirmation: z.string().min(1, "تأكيد كلمة المرور مطلوب."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "كلمتا المرور غير متطابقتين.",
  });
export type PasswordValues = z.infer<typeof passwordSchema>;

/** Login: the format of the identifier, and a password that is merely present. */
export const loginSchema = nationalIdSchema.extend({
  password: z.string().min(1, "كلمة المرور مطلوبة."),
});
export type LoginInput = z.input<typeof loginSchema>;
export type LoginValues = z.output<typeof loginSchema>;
