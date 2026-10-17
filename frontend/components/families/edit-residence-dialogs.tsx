"use client";

import { useEffect, useRef } from "react";
import { useIsFetching } from "@tanstack/react-query";
import { Controller, useForm, useWatch, type FieldValues, type UseFormReset } from "react-hook-form";
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
import { GazaLocationSelector } from "@/components/geography/gaza-location-selector";
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

/**
 * FB-GEO-01 PX-1: a correction dialog must never submit stale text.
 * - The mutation puts the saved residence in the cache at once
 *   (useUpdateFamilyResidence), so the `residence` prop is authoritative.
 * - If newer residence data arrives while the dialog is open, the form is
 *   reset to it (a stale baseline is never kept).
 * - While the family is being refetched, saving is blocked: the latest
 *   state is not known yet.
 */
function useAuthoritativeResidence<TValues extends FieldValues>(
  familyCode: string,
  residence: Residence,
  open: boolean,
  reset: UseFormReset<TValues>,
  toValues: (residence: Residence) => TValues
): boolean {
  const refreshing = useIsFetching({ queryKey: ["families", familyCode], exact: true }) > 0;
  const seen = useRef(residence);
  useEffect(() => {
    if (seen.current === residence) return;
    seen.current = residence;
    if (open) reset(toValues(residence));
  }, [residence, open, reset, toValues]);

  return refreshing;
}

function RefreshingNotice({ show }: { show: boolean }) {
  if (!show) return null;
  return (
    <p className="text-xs text-muted-foreground" role="status" data-residence-refreshing>
      جارٍ تحديث بيانات السكن من الخادم… يمكنك الحفظ بعد اكتمال التحديث.
    </p>
  );
}

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
  const refreshing = useAuthoritativeResidence(familyCode, residence, flow.open, reset, displacementFormValues);

  function onSubmit() {
    if (refreshing) return;
    // Compared with the saved residence: an untouched location is omitted.
    flow.submit(handleSubmit, (values) => toDisplacementPayload(values, residence));
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
            السكن الأصلي هو مكان سكن الأسرة قبل النزوح. مكان النزوح الحالي هو
            المكان الذي تقيم فيه الأسرة النازحة الآن.
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

          <Controller
            control={control}
            name="originalLocationId"
            render={({ field }) => (
              <GazaLocationSelector
                id="edit-originalLocationId"
                label="الموقع الجغرافي للسكن الأصلي (اختياري)"
                value={field.value}
                onChange={field.onChange}
                disabled={flow.isPending}
                error={errors.originalLocationId?.message}
              />
            )}
          />

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
                  // FB-GEO-02: a status change never clears the location text;
                  // it is kept as history (not sent unless DISPLACED).
                  onValueChange={field.onChange}
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
          {!isDisplaced && residence.displacement_location_text && (
            <p className="text-xs text-muted-foreground" data-displacement-text-history>
              مكان النزوح السابق (نص تاريخي): {residence.displacement_location_text} — يبقى محفوظًا ولا يُحذف بتغيير الحالة.
            </p>
          )}
        </form>

        <RefreshingNotice show={refreshing} />
        <EditDialogFooter
          formId="edit-displacement-form"
          isPending={flow.isPending}
          submitDisabled={refreshing}
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
    control,
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

  const refreshing = useAuthoritativeResidence(familyCode, residence, flow.open, reset, currentResidenceFormValues);

  function onSubmit() {
    if (refreshing) return;
    // Compared with the saved residence: an untouched location is omitted.
    flow.submit(handleSubmit, (values) => toCurrentResidencePayload(values, residence));
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
            إلى سكن جديد. اترك المحافظة والمدينة فارغتين إذا لم تذكرهما
            الاستمارة؛ مكان النزوح يُسجَّل في قسم النزوح.
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
              <FieldLabel htmlFor="edit-governorate" optional>المحافظة</FieldLabel>
              <Input id="edit-governorate" {...register("governorate")} />
              <FieldError message={errors.governorate?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-city" optional>المدينة</FieldLabel>
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

          <Controller
            control={control}
            name="currentLocationId"
            render={({ field }) => (
              <GazaLocationSelector
                id="edit-currentLocationId"
                label={
                  residence.displacement_status === "DISPLACED"
                    ? "الموقع الجغرافي الحالي / مكان النزوح (اختياري)"
                    : "الموقع الجغرافي للسكن الحالي (اختياري)"
                }
                value={field.value}
                onChange={field.onChange}
                disabled={flow.isPending}
                error={errors.currentLocationId?.message}
              />
            )}
          />
        </form>

        <RefreshingNotice show={refreshing} />
        <EditDialogFooter
          formId="edit-current-residence-form"
          isPending={flow.isPending}
          submitDisabled={refreshing}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
