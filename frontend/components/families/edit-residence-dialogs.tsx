"use client";

import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
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
} from "@/components/ui/dialog";
import {
  EditDialogFooter,
  EditTrigger,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import { useUpdateFamilyResidence } from "@/lib/api/families";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  currentResidenceApiFieldToFormField,
  currentResidenceFormValues,
  displacementApiFieldToFormField,
  displacementFormValues,
  editCurrentResidenceSchema,
  editDisplacementSchema,
  toCurrentResidencePayload,
  toDisplacementPayload,
  type EditCurrentResidenceValues,
  type EditDisplacementValues,
} from "@/lib/schemas/edit-residence";
import type { FamilyDetail } from "@/lib/types/api/family";

type Residence = NonNullable<FamilyDetail["residence"]>;

const RESIDENCE_STATUS_MESSAGES = {
  403: "لا تملك صلاحية تعديل سكن هذه الأسرة.",
  409: "لا يوجد سكن حالي مسجّل لهذه الأسرة.",
};

// ---------------------------------------------------------------------------

export function EditDisplacementDialog({
  familyCode,
  residence,
}: {
  familyCode: string;
  residence: Residence;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setValue,
    setError,
    formState: { errors },
  } = useForm<EditDisplacementValues>({
    resolver: zodResolver(editDisplacementSchema),
    defaultValues: displacementFormValues(residence),
  });
  const flow = useGuardedSave({
    mutation: useUpdateFamilyResidence(familyCode),
    apiFieldToFormField: displacementApiFieldToFormField,
    setError,
    statusMessages: RESIDENCE_STATUS_MESSAGES,
  });
  const isDisplaced = useWatch({ control, name: "displacementStatus" }) === "DISPLACED";

  function onSubmit() {
    flow.submit(handleSubmit, toDisplacementPayload);
  }

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        // Always start from the current saved data.
        if (next) reset(displacementFormValues(residence));
        flow.setOpen(next);
      }}
    >
      <EditTrigger />
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تعديل بيانات النزوح</DialogTitle>
          <DialogDescription>
            تصحيح السكن الأصلي وحالة النزوح الحالية للأسرة.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id="edit-displacement-form"
          onSubmit={(e) => {
            e.preventDefault();
            onSubmit();
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-originalResidenceText" optional>
              مكان السكن الأصلي قبل النزوح
            </FieldLabel>
            <Input
              id="edit-originalResidenceText"
              placeholder="مثال: بني سهيلا – خانيونس"
              {...register("originalResidenceText")}
            />
            <FieldError message={errors.originalResidenceText?.message} />
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-displacementStatus">
              هل الأسرة نازحة حاليًا؟
            </FieldLabel>
            <Controller
              control={control}
              name="displacementStatus"
              render={({ field }) => (
                <Select
                  value={field.value}
                  onValueChange={(value) => {
                    field.onChange(value);
                    // Not displaced / unknown → no displacement location.
                    if (value !== "DISPLACED") {
                      setValue("displacementLocationText", "");
                    }
                  }}
                >
                  <SelectTrigger id="edit-displacementStatus">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="DISPLACED">نعم</SelectItem>
                    <SelectItem value="NOT_DISPLACED">لا</SelectItem>
                    <SelectItem value="UNKNOWN">غير محدد</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError message={errors.displacementStatus?.message} />
          </div>

          {isDisplaced && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-displacementLocationText" optional>
                مكان النزوح الحالي
              </FieldLabel>
              <Input
                id="edit-displacementLocationText"
                placeholder="مثال: مواصي خانيونس"
                {...register("displacementLocationText")}
              />
              <FieldError message={errors.displacementLocationText?.message} />
            </div>
          )}
        </form>

        <EditDialogFooter
          formId="edit-displacement-form"
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

export function EditCurrentResidenceDialog({
  familyCode,
  residence,
}: {
  familyCode: string;
  residence: Residence;
}) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<EditCurrentResidenceValues>({
    resolver: zodResolver(editCurrentResidenceSchema),
    defaultValues: currentResidenceFormValues(residence),
  });
  const flow = useGuardedSave({
    mutation: useUpdateFamilyResidence(familyCode),
    apiFieldToFormField: currentResidenceApiFieldToFormField,
    setError,
    statusMessages: RESIDENCE_STATUS_MESSAGES,
  });

  function onSubmit() {
    flow.submit(handleSubmit, toCurrentResidencePayload);
  }

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(currentResidenceFormValues(residence));
        flow.setOpen(next);
      }}
    >
      <EditTrigger />
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تعديل السكن الحالي</DialogTitle>
          <DialogDescription>
            تصحيح عنوان السكن الحالي للأسرة. لا يُستخدم لتسجيل انتقال الأسرة
            إلى سكن جديد.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id="edit-current-residence-form"
          onSubmit={(e) => {
            e.preventDefault();
            onSubmit();
          }}
          className="flex flex-col gap-4"
        >
          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-governorate">المحافظة</FieldLabel>
              <Input id="edit-governorate" {...register("governorate")} />
              <FieldError message={errors.governorate?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-city">المدينة</FieldLabel>
              <Input id="edit-city" {...register("city")} />
              <FieldError message={errors.city?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-area" optional>
                المنطقة
              </FieldLabel>
              <Input id="edit-area" {...register("area")} />
              <FieldError message={errors.area?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-neighborhood" optional>
                الحي
              </FieldLabel>
              <Input id="edit-neighborhood" {...register("neighborhood")} />
              <FieldError message={errors.neighborhood?.message} />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-addressText" optional>
              العنوان التفصيلي
            </FieldLabel>
            <Textarea id="edit-addressText" rows={2} {...register("addressText")} />
            <FieldError message={errors.addressText?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId="edit-current-residence-form"
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
