"use client";

import { Controller, useFieldArray, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Plus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
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
import { useCreateAssistance, useUpdateAssistance } from "@/lib/api/assistances";
import { useAssistanceCategories } from "@/lib/api/reference";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  assistanceApiFieldToFormField,
  assistanceFormValues,
  assistanceSchema,
  EMPTY_ITEM,
  toAssistancePayload,
  type AssistanceFormValues,
} from "@/lib/schemas/assistance";
import type { Assistance, AssistanceResponse } from "@/lib/types/api/assistance";
import {
  ASSISTANCE_TYPES,
  assistanceTypeLabels,
  CURRENCIES,
  currencyLabels,
} from "@/lib/utils/assistance";

const STATUS_MESSAGES = {
  403: "لا تملك صلاحية إنشاء أو تعديل المساعدات.",
  409: "لا يمكن تعديل هذه البيانات في حالة المساعدة الحالية.",
};

// Radix Select cannot use "" as a value.
const NO_CURRENCY = "NONE";

/**
 * "مساعدة جديدة" (no `assistance`) or "تعديل". While DRAFT everything is
 * editable; once OPEN only description, target count and dates.
 */
export function AssistanceFormDialog({
  assistance,
  definition = true,
  trigger,
  onSaved,
}: {
  assistance?: Assistance;
  definition?: boolean;
  trigger: React.ReactNode;
  onSaved?: (response: AssistanceResponse) => void;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<AssistanceFormValues>({
    resolver: zodResolver(assistanceSchema),
    defaultValues: assistanceFormValues(assistance),
  });
  const items = useFieldArray({ control, name: "items" });
  const createMutation = useCreateAssistance();
  const updateMutation = useUpdateAssistance(assistance?.id ?? "");
  const flow = useGuardedSave({
    mutation: assistance ? updateMutation : createMutation,
    apiFieldToFormField: assistanceApiFieldToFormField,
    setError,
    statusMessages: STATUS_MESSAGES,
    onSuccess: (data) => onSaved?.(data as AssistanceResponse),
  });
  const categories = useAssistanceCategories().data?.data ?? [];
  const keptCategory =
    assistance && !categories.some((c) => c.code === assistance.category.code) ? assistance.category : null;
  const formId = assistance ? `edit-assistance-${assistance.id}` : "new-assistance";

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(assistanceFormValues(assistance));
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{assistance ? "تعديل المساعدة" : "مساعدة جديدة"}</DialogTitle>
          <DialogDescription>
            {definition
              ? "تعريف برنامج أو حملة مساعدة وما تقدّمه لكل مستفيد. هذا تخطيط وليس تسليمًا."
              : "بعد فتح المساعدة يمكن تعديل الوصف والعدد المستهدف والتواريخ فقط."}
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => toAssistancePayload(values, definition));
          }}
          className="flex flex-col gap-4"
        >
          {definition && (
            <>
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor={`${formId}-title`}>اسم المساعدة</FieldLabel>
                <Input id={`${formId}-title`} placeholder="مثال: حزمة إيواء طارئة" {...register("title")} />
                <FieldError message={errors.title?.message} />
              </div>

              <div className="grid gap-4 sm:grid-cols-3">
                <div className="flex flex-col gap-1.5">
                  <FieldLabel htmlFor={`${formId}-category`}>التصنيف</FieldLabel>
                  <Controller
                    control={control}
                    name="categoryCode"
                    render={({ field }) => (
                      <Select value={field.value} onValueChange={field.onChange}>
                        <SelectTrigger id={`${formId}-category`}>
                          <SelectValue placeholder="اختر التصنيف" />
                        </SelectTrigger>
                        <SelectContent>
                          {keptCategory && <SelectItem value={keptCategory.code}>{keptCategory.name}</SelectItem>}
                          {categories.map((c) => (
                            <SelectItem key={c.code} value={c.code}>
                              {c.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                  <FieldError message={errors.categoryCode?.message} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <FieldLabel htmlFor={`${formId}-type`}>نوع المساعدة</FieldLabel>
                  <Controller
                    control={control}
                    name="assistanceType"
                    render={({ field }) => (
                      <Select value={field.value} onValueChange={field.onChange}>
                        <SelectTrigger id={`${formId}-type`}>
                          <SelectValue placeholder="اختر النوع" />
                        </SelectTrigger>
                        <SelectContent>
                          {ASSISTANCE_TYPES.map((t) => (
                            <SelectItem key={t} value={t}>
                              {assistanceTypeLabels[t]}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                  <FieldError message={errors.assistanceType?.message} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <FieldLabel htmlFor={`${formId}-provider`}>الجهة المقدمة</FieldLabel>
                  <Input id={`${formId}-provider`} placeholder="مثال: مبادرة مجتمعية" {...register("providerName")} />
                  <FieldError message={errors.providerName?.message} />
                </div>
              </div>
            </>
          )}

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-target`} optional>
                عدد المستفيدين المستهدف
              </FieldLabel>
              <Input id={`${formId}-target`} inputMode="numeric" dir="ltr" className="text-end" {...register("targetBeneficiaries")} />
              <FieldError message={errors.targetBeneficiaries?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-start`} optional>
                تاريخ البداية
              </FieldLabel>
              <Input id={`${formId}-start`} type="date" dir="ltr" className="text-end" {...register("startDate")} />
              <FieldError message={errors.startDate?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-end`} optional>
                تاريخ النهاية
              </FieldLabel>
              <Input id={`${formId}-end`} type="date" dir="ltr" className="text-end" {...register("endDate")} />
              <FieldError message={errors.endDate?.message} />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-description`} optional>
              الوصف
            </FieldLabel>
            <Textarea id={`${formId}-description`} rows={2} {...register("description")} />
            <FieldError message={errors.description?.message} />
          </div>

          {definition && (
            <fieldset className="flex flex-col gap-2 rounded-lg border p-3">
              <legend className="px-1 text-sm font-medium">عناصر المساعدة</legend>
              <p className="text-xs text-muted-foreground">
                ما يُخطَّط تقديمه لكل مستفيد. القيمة والعملة اختياريتان وتُدخلان معًا.
              </p>
              <div className="hidden grid-cols-[2fr_1fr_1fr_1fr_1.2fr_auto] gap-2 text-xs text-muted-foreground sm:grid">
                <span>اسم العنصر</span>
                <span>الكمية لكل مستفيد</span>
                <span>الوحدة</span>
                <span>قيمة الوحدة</span>
                <span>العملة</span>
                <span className="w-8" />
              </div>
              {items.fields.map((field, index) => {
                const itemErrors = errors.items?.[index];
                return (
                  <div key={field.id} data-item-row={index} className="flex flex-col gap-1">
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_1.2fr_auto]">
                      <Input aria-label="اسم العنصر" placeholder="اسم العنصر" className="col-span-2 sm:col-span-1" {...register(`items.${index}.itemName`)} />
                      <Input aria-label="الكمية لكل مستفيد" placeholder="الكمية" inputMode="decimal" dir="ltr" className="text-end" {...register(`items.${index}.quantity`)} />
                      <Input aria-label="الوحدة" placeholder="الوحدة" {...register(`items.${index}.unit`)} />
                      <Input aria-label="قيمة الوحدة" placeholder="القيمة" inputMode="decimal" dir="ltr" className="text-end" {...register(`items.${index}.unitValue`)} />
                      <Controller
                        control={control}
                        name={`items.${index}.currency`}
                        render={({ field: currency }) => (
                          <Select
                            value={currency.value || NO_CURRENCY}
                            onValueChange={(v) => currency.onChange(v === NO_CURRENCY ? "" : v)}
                          >
                            <SelectTrigger aria-label="العملة">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value={NO_CURRENCY}>بدون قيمة</SelectItem>
                              {CURRENCIES.map((c) => (
                                <SelectItem key={c} value={c}>
                                  {currencyLabels[c]}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        )}
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="حذف العنصر"
                        onClick={() => items.remove(index)}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                    <FieldError
                      message={
                        itemErrors?.itemName?.message ??
                        itemErrors?.quantity?.message ??
                        itemErrors?.unit?.message ??
                        itemErrors?.unitValue?.message ??
                        itemErrors?.currency?.message
                      }
                    />
                  </div>
                );
              })}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-fit"
                onClick={() => items.append({ ...EMPTY_ITEM })}
              >
                <Plus className="size-4" />
                إضافة عنصر
              </Button>
              <FieldError message={errors.items?.message ?? errors.items?.root?.message} />
            </fieldset>
          )}
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel={assistance ? "حفظ التعديلات" : "حفظ كمسودة"}
        />
      </DialogContent>
    </Dialog>
  );
}
