import { z } from "zod";
import type {
  CreateHealthRecordPayload,
  HealthRecord,
  UpdateHealthRecordPayload,
} from "@/lib/types/api/health";

// UX validation only — Laravel's health-record requests and actions are
// authoritative (FEMALE-only, duplicates, family membership).

const optionalDate = z.string().optional();

export const addHealthRecordSchema = z
  .object({
    personCode: z.string().min(1, "اختر الشخص"),
    type: z.enum(["DISABILITY", "CHRONIC_DISEASE", "PREGNANCY", "BREASTFEEDING"], {
      message: "اختر نوع الحالة",
    }),
    disabilityTypeId: z.string().optional(),
    conditionName: z.string().max(255, "النص طويل جدًا").optional(),
    details: z.string().max(2000, "النص طويل جدًا").optional(),
    startedAt: optionalDate,
  })
  .superRefine((values, ctx) => {
    if (values.type === "DISABILITY" && !values.disabilityTypeId) {
      ctx.addIssue({ code: "custom", path: ["disabilityTypeId"], message: "اختر نوع الإعاقة" });
    }
    if (values.type === "CHRONIC_DISEASE" && !values.conditionName?.trim()) {
      ctx.addIssue({ code: "custom", path: ["conditionName"], message: "اسم المرض المزمن مطلوب" });
    }
  });

export type AddHealthRecordValues = z.infer<typeof addHealthRecordSchema>;

export const EMPTY_ADD_HEALTH_RECORD: Partial<AddHealthRecordValues> = {
  personCode: "",
  type: undefined,
  disabilityTypeId: "",
  conditionName: "",
  details: "",
  startedAt: "",
};

/** Sends only the fields that belong to the chosen type. */
export function toCreateHealthRecordPayload(
  values: AddHealthRecordValues
): CreateHealthRecordPayload {
  return {
    person_code: values.personCode,
    type: values.type,
    ...(values.type === "DISABILITY" && { disability_type_id: Number(values.disabilityTypeId) }),
    ...(values.type === "CHRONIC_DISEASE" && { condition_name: values.conditionName?.trim() }),
    details: values.details || null,
    started_at: values.startedAt || null,
  };
}

export const addHealthApiFieldToFormField: Record<string, keyof AddHealthRecordValues> = {
  person_code: "personCode",
  type: "type",
  disability_type_id: "disabilityTypeId",
  condition_name: "conditionName",
  details: "details",
  started_at: "startedAt",
};

// ---------- edit (correction) ----------

export const editHealthRecordSchema = z.object({
  disabilityTypeId: z.string().optional(),
  conditionName: z.string().max(255, "النص طويل جدًا").optional(),
  details: z.string().max(2000, "النص طويل جدًا").optional(),
  startedAt: optionalDate,
});

export type EditHealthRecordValues = z.infer<typeof editHealthRecordSchema>;

export function editHealthRecordFormValues(record: HealthRecord): EditHealthRecordValues {
  return {
    disabilityTypeId: record.disability_type ? String(record.disability_type.id) : "",
    conditionName: record.condition_name ?? "",
    details: record.details ?? "",
    startedAt: record.started_at ?? "",
  };
}

export function toUpdateHealthRecordPayload(
  record: HealthRecord,
  values: EditHealthRecordValues
): UpdateHealthRecordPayload {
  return {
    ...(record.type === "DISABILITY" && { disability_type_id: Number(values.disabilityTypeId) }),
    ...(record.type === "CHRONIC_DISEASE" && { condition_name: values.conditionName?.trim() }),
    details: values.details || null,
    started_at: values.startedAt || null,
  };
}

export const editHealthApiFieldToFormField: Record<string, keyof EditHealthRecordValues> = {
  disability_type_id: "disabilityTypeId",
  condition_name: "conditionName",
  details: "details",
  started_at: "startedAt",
};

// ---------- close ----------

export const closeHealthRecordSchema = z.object({
  endedAt: optionalDate,
});

export type CloseHealthRecordValues = z.infer<typeof closeHealthRecordSchema>;

export const closeHealthApiFieldToFormField: Record<string, keyof CloseHealthRecordValues> = {
  ended_at: "endedAt",
};
