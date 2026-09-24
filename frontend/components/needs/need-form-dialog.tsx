"use client";

import { Controller, useForm, useWatch, type Control, type FieldErrors, type UseFormRegister } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { cn } from "cn";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  EditDialogFooter,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import { useFamilyAssessments } from "@/lib/api/assessments";
import { ApiError } from "@/lib/api/client";
import { useFamily } from "@/lib/api/families";
import { useCreateNeed, useUpdateNeed } from "@/lib/api/needs";
import { useNeedCategories } from "@/lib/api/reference";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  needApiFieldToFormField,
  needFormValues,
  needSchema,
  toNeedPayload,
  type NeedFormValues,
} from "@/lib/schemas/need";
import type { Need, NeedPriority, NeedResponse } from "@/lib/types/api/need";
import { NEED_PRIORITIES, needPriorityLabels } from "@/lib/utils/need";

const NEED_STATUS_MESSAGES = {
  403: "لا تملك صلاحية إضافة أو تعديل الاحتياجات.",
  409: "هذا الاحتياج مُغلق ولا يمكن تعديله.",
};

function Segmented<T extends string>({
  id,
  value,
  onChange,
  options,
}: {
  id: string;
  value: T;
  onChange: (value: T) => void;
  options: { value: T; label: string }[];
}) {
  return (
    <div role="radiogroup" aria-labelledby={id} className="flex flex-wrap gap-1.5">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={value === option.value}
          onClick={() => onChange(option.value)}
          className={cn(
            "h-8 rounded-md border px-3 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none",
            value === option.value
              ? "border-primary bg-primary text-primary-foreground"
              : "border-border bg-background text-muted-foreground hover:bg-muted"
          )}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

/** Data-backed fields; mounted only while the dialog is open. */
function NeedFields({
  formId,
  familyCode,
  need,
  control,
  register,
  errors,
}: {
  formId: string;
  familyCode: string;
  need?: Need;
  control: Control<NeedFormValues>;
  register: UseFormRegister<NeedFormValues>;
  errors: FieldErrors<NeedFormValues>;
}) {
  const target = useWatch({ control, name: "target" });
  const source = useWatch({ control, name: "source" });
  const familyQuery = useFamily(familyCode);
  const categoriesQuery = useNeedCategories();
  const assessmentsQuery = useFamilyAssessments(familyCode);

  // Only active members can be targeted; an existing target is kept.
  const members = (familyQuery.data?.data.members ?? []).filter(
    (m) => m.is_active || m.person_code === need?.person?.person_code
  );
  const keptPerson = need?.person && !members.some((m) => m.person_code === need.person!.person_code)
    ? need.person
    : null;

  const categories = categoriesQuery.data?.data ?? [];
  const keptCategory = need && !categories.some((c) => c.code === need.category.code) ? need.category : null;

  // A source must be a COMPLETED assessment of this family.
  const assessments = (assessmentsQuery.data?.pages.flatMap((p) => p.data) ?? []).filter(
    (a) => a.status === "COMPLETED"
  );
  const keptAssessment =
    need?.source_assessment && !assessments.some((a) => a.id === need.source_assessment!.id)
      ? need.source_assessment
      : null;
  const noAssessmentAccess =
    assessmentsQuery.error instanceof ApiError && assessmentsQuery.error.status === 403;

  return (
    <>
      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${formId}-target`}>المستفيد</FieldLabel>
        <Controller
          control={control}
          name="target"
          render={({ field }) => (
            <Segmented
              id={`${formId}-target`}
              value={field.value}
              onChange={field.onChange}
              options={[
                { value: "family", label: "الأسرة كاملة" },
                { value: "person", label: "فرد من الأسرة" },
              ]}
            />
          )}
        />
        {target === "person" && (
          <Controller
            control={control}
            name="personCode"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id={`${formId}-person`} aria-label="فرد الأسرة">
                  <SelectValue placeholder={familyQuery.isLoading ? "جارٍ التحميل..." : "اختر فردًا من الأسرة"} />
                </SelectTrigger>
                <SelectContent>
                  {keptPerson && (
                    <SelectItem value={keptPerson.person_code}>{keptPerson.full_name}</SelectItem>
                  )}
                  {members.map((member) => (
                    <SelectItem key={member.person_code} value={member.person_code}>
                      {member.full_name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
        <FieldError message={errors.personCode?.message} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor={`${formId}-category`}>التصنيف</FieldLabel>
          <Controller
            control={control}
            name="categoryCode"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange} disabled={categoriesQuery.isLoading}>
                <SelectTrigger id={`${formId}-category`}>
                  <SelectValue placeholder={categoriesQuery.isLoading ? "جارٍ التحميل..." : "اختر التصنيف"} />
                </SelectTrigger>
                <SelectContent>
                  {keptCategory && <SelectItem value={keptCategory.code}>{keptCategory.name}</SelectItem>}
                  {categories.map((category) => (
                    <SelectItem key={category.code} value={category.code}>
                      {category.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.categoryCode?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor={`${formId}-priority`}>الأولوية</FieldLabel>
          <Controller
            control={control}
            name="priority"
            render={({ field }) => (
              <Select value={field.value} onValueChange={(v) => field.onChange(v as NeedPriority)}>
                <SelectTrigger id={`${formId}-priority`}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {NEED_PRIORITIES.map((priority) => (
                    <SelectItem key={priority} value={priority}>
                      {needPriorityLabels[priority]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.priority?.message} />
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${formId}-title`}>عنوان الاحتياج</FieldLabel>
        <Input id={`${formId}-title`} placeholder="مثال: كرسي متحرك" {...register("title")} />
        <FieldError message={errors.title?.message} />
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor={`${formId}-quantity`} optional>
            الكمية
          </FieldLabel>
          <Input id={`${formId}-quantity`} inputMode="decimal" dir="ltr" className="text-end" {...register("quantity")} />
          <FieldError message={errors.quantity?.message} />
        </div>
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor={`${formId}-unit`} optional>
            الوحدة
          </FieldLabel>
          <Input id={`${formId}-unit`} placeholder="مثال: قطعة" {...register("unit")} />
          <FieldError message={errors.unit?.message} />
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${formId}-source`}>المصدر</FieldLabel>
        <Controller
          control={control}
          name="source"
          render={({ field }) => (
            <Segmented
              id={`${formId}-source`}
              value={field.value}
              onChange={field.onChange}
              options={[
                { value: "direct", label: "إدخال مباشر" },
                { value: "assessment", label: "تقييم سابق" },
              ]}
            />
          )}
        />
        {source === "assessment" &&
          (noAssessmentAccess ? (
            <p className="text-xs text-muted-foreground">لا تملك صلاحية عرض تقييمات هذه الأسرة.</p>
          ) : (
            <Controller
              control={control}
              name="sourceAssessmentId"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id={`${formId}-assessment`} aria-label="التقييم">
                    <SelectValue
                      placeholder={
                        assessmentsQuery.isLoading
                          ? "جارٍ التحميل..."
                          : assessments.length === 0 && !keptAssessment
                            ? "لا توجد تقييمات مكتملة لهذه الأسرة"
                            : "اختر تقييمًا مكتملًا"
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {keptAssessment && (
                      <SelectItem value={keptAssessment.id}>
                        تقييم بتاريخ {keptAssessment.assessment_date}
                      </SelectItem>
                    )}
                    {assessments.map((assessment) => (
                      <SelectItem key={assessment.id} value={assessment.id}>
                        تقييم بتاريخ {assessment.assessment_date} ({assessment.assessed_domain_count} مجالات)
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          ))}
        <FieldError message={errors.sourceAssessmentId?.message} />
      </div>

      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${formId}-description`} optional>
          ملاحظات / وصف
        </FieldLabel>
        <Textarea id={`${formId}-description`} rows={3} {...register("description")} />
        <FieldError message={errors.description?.message} />
      </div>
    </>
  );
}

/**
 * "احتياج جديد" (no `need`) or "تعديل" of an OPEN Need. `trigger` is the
 * button that opens it; `sourceAssessmentId` preselects an assessment.
 */
export function NeedFormDialog({
  familyCode,
  need,
  sourceAssessmentId,
  trigger,
  onSaved,
}: {
  familyCode: string;
  need?: Need;
  sourceAssessmentId?: string;
  trigger: React.ReactNode;
  onSaved?: (response: NeedResponse) => void;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<NeedFormValues>({
    resolver: zodResolver(needSchema),
    defaultValues: needFormValues(need, sourceAssessmentId),
  });
  const createMutation = useCreateNeed(familyCode);
  const updateMutation = useUpdateNeed(familyCode, need?.id ?? "");
  const flow = useGuardedSave({
    mutation: need ? updateMutation : createMutation,
    apiFieldToFormField: needApiFieldToFormField,
    setError,
    statusMessages: NEED_STATUS_MESSAGES,
    onSuccess: (data) => onSaved?.(data as NeedResponse),
  });
  const formId = need ? `edit-need-${need.id}` : "new-need";

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(needFormValues(need, sourceAssessmentId));
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{need ? "تعديل الاحتياج" : "احتياج جديد"}</DialogTitle>
          <DialogDescription>
            احتياج محدد للأسرة أو لأحد أفرادها. يُسجَّل مفتوحًا حتى تتم تلبيته أو إغلاقه.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, toNeedPayload);
          }}
          className="flex flex-col gap-4"
        >
          {flow.open && (
            <NeedFields
              formId={formId}
              familyCode={familyCode}
              need={need}
              control={control}
              register={register}
              errors={errors}
            />
          )}
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel={need ? "حفظ التعديلات" : "إضافة الاحتياج"}
        />
      </DialogContent>
    </Dialog>
  );
}
