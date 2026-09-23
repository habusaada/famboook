"use client";

import { useRef, useState } from "react";
import {
  Controller,
  useForm,
  useWatch,
  type FieldValues,
  type Path,
  type UseFormSetError,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useUpdateFamilyResidence } from "@/lib/api/families";
import { ApiError } from "@/lib/api/client";
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
import type {
  FamilyDetail,
  UpdateFamilyResidencePayload,
} from "@/lib/types/api/family";

type Residence = NonNullable<FamilyDetail["residence"]>;

function FieldLabel({
  htmlFor,
  optional,
  children,
}: {
  htmlFor: string;
  optional?: boolean;
  children: React.ReactNode;
}) {
  return (
    <Label htmlFor={htmlFor}>
      {children}
      {optional && (
        <span className="text-xs font-normal text-muted-foreground">
          (اختياري)
        </span>
      )}
    </Label>
  );
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

/**
 * Shared save flow for both residence dialogs: one request at a time,
 * Laravel 422 errors mapped onto form fields, close only on success.
 */
function useResidenceSave<T extends FieldValues>(
  familyCode: string,
  apiFieldToFormField: Record<string, Path<T>>,
  setError: UseFormSetError<T>
) {
  const [open, setOpen] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const update = useUpdateFamilyResidence(familyCode);
  // Synchronous guard: isPending only flips after async validation, so a
  // fast double click could otherwise send two PATCHes.
  const submitting = useRef(false);

  function save(payload: UpdateFamilyResidencePayload) {
    setSubmitError(null);

    update.mutate(payload, {
      onSettled: () => {
        submitting.current = false;
      },
      onSuccess: () => setOpen(false),
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          for (const [apiField, messages] of Object.entries(error.validationErrors ?? {})) {
            const formField = apiFieldToFormField[apiField];
            if (formField && messages[0]) {
              setError(formField, { type: "server", message: messages[0] });
            }
          }
          setSubmitError(error.message422 ?? "توجد أخطاء في البيانات المُدخلة.");
          return;
        }

        if (error instanceof ApiError && error.status === 401) {
          setSubmitError("انتهت جلسة الدخول. يرجى تسجيل الدخول مجددًا.");
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setSubmitError("لا تملك صلاحية تعديل سكن هذه الأسرة.");
          return;
        }

        if (error instanceof ApiError && error.status === 409) {
          setSubmitError("لا يوجد سكن حالي مسجّل لهذه الأسرة.");
          return;
        }

        setSubmitError("تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
      },
    });
  }

  return {
    open,
    // Not while a request is in flight: its result would land on a
    // dialog the user already dismissed.
    setOpen: (next: boolean) => {
      if (next || !update.isPending) setOpen(next);
    },
    submitError,
    clearSubmitError: () => setSubmitError(null),
    isPending: update.isPending,
    // Returns false if a save is already in flight.
    begin: () => {
      if (submitting.current) return false;
      submitting.current = true;
      return true;
    },
    release: () => {
      submitting.current = false;
    },
    save,
  };
}

function EditTrigger() {
  return (
    <DialogTrigger asChild>
      <Button variant="outline" size="sm">
        <Pencil className="size-4" />
        تعديل
      </Button>
    </DialogTrigger>
  );
}

function SaveError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر حفظ التعديلات</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

function Footer({
  formId,
  isPending,
  onCancel,
}: {
  formId: string;
  isPending: boolean;
  onCancel: () => void;
}) {
  return (
    <DialogFooter>
      <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
        إلغاء
      </Button>
      <Button type="submit" form={formId} disabled={isPending}>
        {isPending ? "جارٍ الحفظ..." : "حفظ التعديلات"}
      </Button>
    </DialogFooter>
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
    setValue,
    setError,
    formState: { errors },
  } = useForm<EditDisplacementValues>({
    resolver: zodResolver(editDisplacementSchema),
    defaultValues: displacementFormValues(residence),
  });
  const flow = useResidenceSave(familyCode, displacementApiFieldToFormField, setError);
  const isDisplaced = useWatch({ control, name: "displacementStatus" }) === "DISPLACED";

  function onSubmit() {
    if (!flow.begin()) return;
    void handleSubmit(
      (values) => flow.save(toDisplacementPayload(values)),
      flow.release
    )();
  }

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        // Always start from the current saved data.
        if (next) {
          reset(displacementFormValues(residence));
          flow.clearSubmitError();
        }
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

        <Footer
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
  const flow = useResidenceSave(familyCode, currentResidenceApiFieldToFormField, setError);

  function onSubmit() {
    if (!flow.begin()) return;
    void handleSubmit(
      (values) => flow.save(toCurrentResidencePayload(values)),
      flow.release
    )();
  }

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) {
          reset(currentResidenceFormValues(residence));
          flow.clearSubmitError();
        }
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

        <Footer
          formId="edit-current-residence-form"
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
