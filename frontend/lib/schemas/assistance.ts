import { z } from "zod";
import type { AssessmentRating } from "@/lib/types/api/assessment";
import type {
  Assistance,
  AssistancePayload,
  Currency,
  TargetingCriteria,
} from "@/lib/types/api/assistance";
import type { NeedPriority } from "@/lib/types/api/need";

// UX validation only — Laravel's assistance requests, actions and the
// targeting engine are authoritative.

const DECIMAL = /^\d+(\.\d{1,2})?$/;
const positiveDecimal = (message: string) =>
  z
    .string()
    .trim()
    .refine((v) => v === "" || (DECIMAL.test(v) && Number(v) > 0), message);

export const assistanceItemSchema = z
  .object({
    itemName: z.string().trim().min(1, "اسم العنصر مطلوب").max(150, "الاسم طويل جدًا"),
    quantity: positiveDecimal("الكمية يجب أن تكون رقمًا أكبر من صفر"),
    unit: z.string().trim().max(30, "الوحدة طويلة جدًا"),
    unitValue: positiveDecimal("القيمة يجب أن تكون رقمًا أكبر من صفر"),
    currency: z.string(),
  })
  .superRefine((item, ctx) => {
    if (item.unitValue && !item.currency) {
      ctx.addIssue({ code: "custom", path: ["currency"], message: "اختر العملة" });
    }
    if (!item.unitValue && item.currency) {
      ctx.addIssue({ code: "custom", path: ["unitValue"], message: "أدخل القيمة أو احذف العملة" });
    }
  });

export const assistanceSchema = z
  .object({
    title: z.string().trim().min(1, "اسم المساعدة مطلوب").max(150, "الاسم طويل جدًا"),
    categoryCode: z.string().min(1, "اختر التصنيف"),
    assistanceType: z.string().min(1, "اختر نوع المساعدة"),
    executionMode: z.string().min(1, "اختر طريقة التنفيذ"),
    providerName: z.string().trim().min(1, "الجهة المقدمة مطلوبة").max(150, "الاسم طويل جدًا"),
    targetBeneficiaries: z
      .string()
      .trim()
      .refine((v) => v === "" || (/^\d+$/.test(v) && Number(v) > 0), "أدخل عددًا صحيحًا أكبر من صفر"),
    startDate: z.string(),
    endDate: z.string(),
    description: z.string().max(5000, "النص طويل جدًا"),
    items: z.array(assistanceItemSchema).max(50, "عدد العناصر كبير جدًا"),
  })
  .superRefine((values, ctx) => {
    if (values.startDate && values.endDate && values.endDate < values.startDate) {
      ctx.addIssue({ code: "custom", path: ["endDate"], message: "تاريخ النهاية يجب ألا يسبق تاريخ البداية" });
    }
  });

export type AssistanceFormValues = z.infer<typeof assistanceSchema>;

export const EMPTY_ITEM: AssistanceFormValues["items"][number] = {
  itemName: "",
  quantity: "",
  unit: "",
  unitValue: "",
  currency: "",
};

export function assistanceFormValues(assistance?: Assistance): AssistanceFormValues {
  return {
    title: assistance?.title ?? "",
    categoryCode: assistance?.category.code ?? "",
    assistanceType: assistance?.assistance_type ?? "",
    executionMode: assistance?.execution_mode ?? "",
    providerName: assistance?.provider_name ?? "",
    targetBeneficiaries: assistance?.target_beneficiaries ? String(assistance.target_beneficiaries) : "",
    startDate: assistance?.start_date ?? "",
    endDate: assistance?.end_date ?? "",
    description: assistance?.description ?? "",
    items: assistance?.items?.map((item) => ({
      itemName: item.item_name,
      quantity: item.quantity_per_beneficiary ?? "",
      unit: item.unit ?? "",
      unitValue: item.unit_value ?? "",
      currency: item.currency ?? "",
    })) ?? [{ ...EMPTY_ITEM }],
  };
}

/**
 * `definition` = DRAFT (everything). Otherwise only the fields editable
 * after opening: description, target count and planned dates.
 */
export function toAssistancePayload(values: AssistanceFormValues, definition: boolean): AssistancePayload {
  const limited: AssistancePayload = {
    target_beneficiaries: values.targetBeneficiaries ? Number(values.targetBeneficiaries) : null,
    start_date: values.startDate || null,
    end_date: values.endDate || null,
    description: values.description.trim() || null,
  };
  if (!definition) return limited;

  return {
    ...limited,
    title: values.title.trim(),
    category_code: values.categoryCode,
    assistance_type: values.assistanceType as AssistancePayload["assistance_type"],
    execution_mode: values.executionMode as AssistancePayload["execution_mode"],
    provider_name: values.providerName.trim(),
    items: values.items.map((item) => ({
      item_name: item.itemName.trim(),
      quantity_per_beneficiary: item.quantity ? Number(item.quantity) : null,
      unit: item.unit.trim() || null,
      unit_value: item.unitValue ? Number(item.unitValue) : null,
      currency: (item.currency || null) as Currency | null,
    })),
  };
}

export const assistanceApiFieldToFormField: Record<string, keyof AssistanceFormValues> = {
  title: "title",
  category_code: "categoryCode",
  assistance_type: "assistanceType",
  execution_mode: "executionMode",
  provider_name: "providerName",
  target_beneficiaries: "targetBeneficiaries",
  start_date: "startDate",
  end_date: "endDate",
  description: "description",
};

// ---------- targeting ----------

// Tri-state for boolean criteria: "" = ignore, "yes" = must have, "no" = must not have.
const triState = z.enum(["", "yes", "no"]);
const optionalCount = (max: number) =>
  z
    .string()
    .trim()
    .refine((v) => v === "" || (/^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= max), `أدخل عددًا بين 1 و${max}`);

export const targetingSchema = z
  .object({
    minMembers: optionalCount(100),
    maxMembers: optionalCount(100),
    displacement: z.enum(["", "DISPLACED", "NOT_DISPLACED"]),
    location: z.string().trim().max(100, "النص طويل جدًا"),
    childUnderTwo: triState,
    minChildrenUnderTwo: optionalCount(20),
    pregnant: triState,
    breastfeeding: triState,
    disability: triState,
    chronic: triState,
    needCategory: z.string(),
    needPriorities: z.array(z.string()),
    assessmentDomain: z.string(),
    assessmentRatings: z.array(z.string()),
  })
  .superRefine((v, ctx) => {
    if (v.minMembers && v.maxMembers && Number(v.maxMembers) < Number(v.minMembers)) {
      ctx.addIssue({ code: "custom", path: ["maxMembers"], message: "الحد الأعلى يجب ألا يقل عن الحد الأدنى" });
    }
    if (v.minChildrenUnderTwo && v.childUnderTwo === "no") {
      ctx.addIssue({ code: "custom", path: ["minChildrenUnderTwo"], message: "لا يتوافق مع اشتراط عدم وجود أطفال دون سنتين" });
    }
    if (v.assessmentRatings.length > 0 && !v.assessmentDomain) {
      ctx.addIssue({ code: "custom", path: ["assessmentDomain"], message: "اختر مجال التقييم" });
    }
  });

export type TargetingFormValues = z.infer<typeof targetingSchema>;

const fromTri = (v: "" | "yes" | "no"): boolean | undefined => (v === "" ? undefined : v === "yes");

export function toTargetingCriteria(v: TargetingFormValues): TargetingCriteria {
  const criteria: TargetingCriteria = {
    min_family_members: v.minMembers ? Number(v.minMembers) : undefined,
    max_family_members: v.maxMembers ? Number(v.maxMembers) : undefined,
    displacement_status: v.displacement || undefined,
    displacement_location_text: v.location || undefined,
    has_child_under_two: fromTri(v.childUnderTwo),
    min_children_under_two: v.minChildrenUnderTwo ? Number(v.minChildrenUnderTwo) : undefined,
    has_pregnant_member: fromTri(v.pregnant),
    has_breastfeeding_member: fromTri(v.breastfeeding),
    has_member_with_disability: fromTri(v.disability),
    has_member_with_chronic_disease: fromTri(v.chronic),
    need_category_code: v.needCategory || undefined,
    need_priorities: v.needPriorities.length ? (v.needPriorities as NeedPriority[]) : undefined,
    assessment_domain_code: v.assessmentDomain || undefined,
    assessment_ratings: v.assessmentRatings.length ? (v.assessmentRatings as AssessmentRating[]) : undefined,
  };
  return Object.fromEntries(Object.entries(criteria).filter(([, value]) => value !== undefined));
}

const toTri = (v: boolean | undefined): "" | "yes" | "no" => (v === undefined ? "" : v ? "yes" : "no");

export function targetingFormValues(c: TargetingCriteria = {}): TargetingFormValues {
  return {
    minMembers: c.min_family_members ? String(c.min_family_members) : "",
    maxMembers: c.max_family_members ? String(c.max_family_members) : "",
    displacement: c.displacement_status ?? "",
    location: c.displacement_location_text ?? "",
    childUnderTwo: toTri(c.has_child_under_two),
    minChildrenUnderTwo: c.min_children_under_two ? String(c.min_children_under_two) : "",
    pregnant: toTri(c.has_pregnant_member),
    breastfeeding: toTri(c.has_breastfeeding_member),
    disability: toTri(c.has_member_with_disability),
    chronic: toTri(c.has_member_with_chronic_disease),
    needCategory: c.need_category_code ?? "",
    needPriorities: c.need_priorities ?? [],
    assessmentDomain: c.assessment_domain_code ?? "",
    assessmentRatings: c.assessment_ratings ?? [],
  };
}
