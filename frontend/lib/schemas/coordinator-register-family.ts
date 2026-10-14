import { z } from "zod";
import { cleanDigits, isPlaceholderNationalId } from "@/lib/utils/national-id";

// The Coordinator's FAMILY_ADD form (CO-02.5 / CO-02.6b). Laravel
// (RegisterFamilyHandler) is the validation authority; these rules only
// spare a round trip. The head's National ID is REQUIRED — nine digits,
// never a placeholder — and sent as nine ASCII digits (the shared registry
// helper, lib/utils/national-id.ts). The ORIGINAL residence only: there is
// no current-residence field on purpose.

const nationalId = z
  .string()
  .transform(cleanDigits)
  .refine((v) => v.length > 0, "رقم هوية رب الأسرة مطلوب.")
  .refine((v) => v === "" || /^[0-9]{9}$/.test(v), "رقم الهوية يجب أن يتكون من 9 أرقام.")
  .refine((v) => !isPlaceholderNationalId(v), "أدخل رقم الهوية الحقيقي كما في الوثيقة.");

const mobile = z
  .string()
  .transform(cleanDigits)
  .refine((v) => v === "" || /^05[0-9]{8}$/.test(v), "رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.");

/** No Branch: the "Clan level" choice of the placement selector. */
export const NO_BRANCH = "__NONE__";

export const registerFamilySchema = z.object({
  clanCode: z.string().refine((v) => v !== "", "اختر العائلة."),
  /** "" = not chosen yet; NO_BRANCH = Clan level (only where allowed). */
  branchCode: z.string().refine((v) => v !== "", "اختر الفرع."),
  fullName: z.string().refine((v) => v.trim().length > 0, "اسم رب الأسرة مطلوب.").refine((v) => v.trim().length <= 255, "الاسم طويل جدًا."),
  nationalId,
  gender: z.enum(["", "MALE", "FEMALE"]).refine((v) => v !== "", "اختر الجنس."),
  birthDate: z
    .string()
    .refine((v) => v === "" || /^\d{4}-\d{2}-\d{2}$/.test(v), "تاريخ غير صالح.")
    .refine((v) => v === "" || v <= new Date().toISOString().slice(0, 10), "لا يمكن أن يكون تاريخ الميلاد في المستقبل."),
  maritalStatus: z.enum(["", "SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"]),
  mobile,
  originalResidence: z
    .string()
    .refine((v) => v.trim().length > 0, "مكان السكن الأصلي مطلوب.")
    .refine((v) => v.trim().length <= 255, "النص طويل جدًا."),
  reason: z.string().max(2000, "النص طويل جدًا."),
});

export type RegisterFamilyInput = z.input<typeof registerFamilySchema>;
export type RegisterFamilyValues = z.output<typeof registerFamilySchema>;

export const REGISTER_FAMILY_DEFAULTS: RegisterFamilyInput = {
  clanCode: "",
  branchCode: "",
  fullName: "",
  nationalId: "",
  gender: "",
  birthDate: "",
  maritalStatus: "",
  mobile: "",
  originalResidence: "",
  reason: "",
};

/** The placement of POST …/coordinator/requests. */
export function toPlacement(values: RegisterFamilyValues) {
  return { clan_code: values.clanCode, branch_code: values.branchCode === NO_BRANCH ? null : values.branchCode };
}

/** The `data` of POST …/coordinator/requests for REGISTER_FAMILY. */
export function toRegisterFamilyData(values: RegisterFamilyValues) {
  return {
    household_head: {
      full_name: values.fullName.trim(),
      national_id: values.nationalId,
      gender: values.gender,
      birth_date: values.birthDate === "" ? null : values.birthDate,
      marital_status: values.maritalStatus === "" ? null : values.maritalStatus,
      mobile: values.mobile === "" ? null : values.mobile,
    },
    residence: { original_residence_text: values.originalResidence.trim() },
  };
}

/** Server field → form field. */
export const REGISTER_FAMILY_FIELDS: Record<string, keyof RegisterFamilyInput> = {
  placement: "clanCode",
  "placement.clan_code": "clanCode",
  "placement.branch_code": "branchCode",
  "household_head.full_name": "fullName",
  "household_head.national_id": "nationalId",
  "household_head.gender": "gender",
  "household_head.birth_date": "birthDate",
  "household_head.marital_status": "maritalStatus",
  "household_head.mobile": "mobile",
  "residence.original_residence_text": "originalResidence",
  reason: "reason",
};
