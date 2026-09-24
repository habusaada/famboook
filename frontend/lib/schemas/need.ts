import { z } from "zod";
import type { Need, NeedPayload } from "@/lib/types/api/need";

// UX validation only — Laravel's need requests and actions are
// authoritative (active membership, same-family completed assessment,
// active category, resolution rules).

export const needSchema = z
  .object({
    target: z.enum(["family", "person"]),
    personCode: z.string(),
    categoryCode: z.string().min(1, "اختر التصنيف"),
    title: z.string().trim().min(1, "عنوان الاحتياج مطلوب").max(150, "العنوان طويل جدًا"),
    priority: z.enum(["LOW", "MEDIUM", "HIGH", "URGENT"]),
    quantity: z.string().trim(),
    unit: z.string().trim().max(30, "الوحدة طويلة جدًا"),
    source: z.enum(["direct", "assessment"]),
    sourceAssessmentId: z.string(),
    description: z.string().max(2000, "النص طويل جدًا"),
  })
  .superRefine((values, ctx) => {
    if (values.target === "person" && !values.personCode) {
      ctx.addIssue({ code: "custom", path: ["personCode"], message: "اختر فردًا من الأسرة" });
    }
    if (values.source === "assessment" && !values.sourceAssessmentId) {
      ctx.addIssue({ code: "custom", path: ["sourceAssessmentId"], message: "اختر التقييم" });
    }
    if (values.quantity) {
      if (!/^\d+(\.\d{1,2})?$/.test(values.quantity) || Number(values.quantity) <= 0) {
        ctx.addIssue({
          code: "custom",
          path: ["quantity"],
          message: "الكمية يجب أن تكون رقمًا أكبر من صفر (منزلتان عشريتان كحد أقصى)",
        });
      }
    } else if (values.unit) {
      ctx.addIssue({ code: "custom", path: ["quantity"], message: "أدخل الكمية عند تحديد الوحدة" });
    }
  });

export type NeedFormValues = z.infer<typeof needSchema>;

export function needFormValues(need?: Need, sourceAssessmentId?: string): NeedFormValues {
  const source = need?.source_assessment?.id ?? sourceAssessmentId ?? "";
  return {
    target: need?.person ? "person" : "family",
    personCode: need?.person?.person_code ?? "",
    categoryCode: need?.category.code ?? "",
    title: need?.title ?? "",
    priority: need?.priority ?? "MEDIUM",
    quantity: need?.quantity ?? "",
    unit: need?.unit ?? "",
    source: source ? "assessment" : "direct",
    sourceAssessmentId: source,
    description: need?.description ?? "",
  };
}

/** The full editable state; switching target/source clears the other side. */
export function toNeedPayload(values: NeedFormValues): NeedPayload {
  return {
    person_code: values.target === "person" ? values.personCode : null,
    source_assessment_id: values.source === "assessment" ? values.sourceAssessmentId : null,
    category_code: values.categoryCode,
    title: values.title.trim(),
    priority: values.priority,
    quantity: values.quantity ? Number(values.quantity) : null,
    unit: values.quantity && values.unit ? values.unit : null,
    description: values.description.trim() || null,
  };
}

export const needApiFieldToFormField: Record<string, keyof NeedFormValues> = {
  person_code: "personCode",
  source_assessment_id: "sourceAssessmentId",
  category_code: "categoryCode",
  title: "title",
  priority: "priority",
  quantity: "quantity",
  unit: "unit",
  description: "description",
};

export const closeNeedSchema = z.object({
  closureReason: z.string().trim().min(1, "سبب الإغلاق مطلوب").max(1000, "النص طويل جدًا"),
});

export type CloseNeedValues = z.infer<typeof closeNeedSchema>;
