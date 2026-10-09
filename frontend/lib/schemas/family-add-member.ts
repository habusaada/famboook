import { z } from "zod";

// The household head's ADD_FAMILY_MEMBER form (docs/11 FP-ADR-076). Laravel
// (AddFamilyMemberHandler) is the validation authority; these rules only
// spare a round trip. The National ID is REQUIRED — never a placeholder —
// and is sent as nine ASCII digits.

const ARABIC_DIGITS: Record<string, string> = {
  "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
  "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
};

/** Arabic-Indic / Persian digits → ASCII; spaces and - . / _ removed (as FamilyInput::clean). */
export function cleanDigits(value: string): string {
  return value.replace(/[٠-٩۰-۹]/g, (d) => ARABIC_DIGITS[d] ?? d).replace(/[\s\-./_‎‏؜​-‍‪-‮⁦-⁩﻿]/g, "");
}

const nationalId = z
  .string()
  .transform(cleanDigits)
  .refine((v) => v.length > 0, "رقم الهوية مطلوب.")
  .refine((v) => v === "" || /^[0-9]{9}$/.test(v), "رقم الهوية يجب أن يتكون من 9 أرقام.")
  .refine((v) => !/^([0-9])\1{8}$/.test(v), "أدخل رقم الهوية الحقيقي كما في الوثيقة.");

const mobile = z
  .string()
  .transform(cleanDigits)
  .refine((v) => v === "" || /^05[0-9]{8}$/.test(v), "رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.");

export const addMemberSchema = z.object({
  fullName: z.string().refine((v) => v.trim().length > 0, "الاسم الكامل مطلوب.").refine((v) => v.trim().length <= 255, "الاسم طويل جدًا."),
  nationalId,
  gender: z.enum(["", "MALE", "FEMALE"]).refine((v) => v !== "", "اختر الجنس."),
  relationship: z.string().refine((v) => v !== "", "اختر صلة القرابة برب الأسرة."),
  birthDate: z
    .string()
    .refine((v) => v === "" || /^\d{4}-\d{2}-\d{2}$/.test(v), "تاريخ غير صالح.")
    .refine((v) => v === "" || v <= new Date().toISOString().slice(0, 10), "لا يمكن أن يكون تاريخ الميلاد في المستقبل."),
  maritalStatus: z.enum(["", "SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"]),
  mobile,
  reason: z.string().max(2000, "النص طويل جدًا."),
});

export type AddMemberInput = z.input<typeof addMemberSchema>;
export type AddMemberValues = z.output<typeof addMemberSchema>;

export const ADD_MEMBER_DEFAULTS: AddMemberInput = {
  fullName: "",
  nationalId: "",
  gender: "",
  relationship: "",
  birthDate: "",
  maritalStatus: "",
  mobile: "",
  reason: "",
};

/** The `data` of POST /api/v1/family/change-requests for ADD_FAMILY_MEMBER. */
export function toAddMemberData(values: AddMemberValues) {
  return {
    full_name: values.fullName.trim(),
    national_id: values.nationalId,
    gender: values.gender,
    relationship: values.relationship,
    birth_date: values.birthDate === "" ? null : values.birthDate,
    marital_status: values.maritalStatus === "" ? null : values.maritalStatus,
    mobile: values.mobile === "" ? null : values.mobile,
  };
}

/** Server field (data key) → form field. */
export const ADD_MEMBER_FIELDS: Record<string, keyof AddMemberInput> = {
  full_name: "fullName",
  national_id: "nationalId",
  gender: "gender",
  relationship: "relationship",
  birth_date: "birthDate",
  marital_status: "maritalStatus",
  mobile: "mobile",
  reason: "reason",
};
