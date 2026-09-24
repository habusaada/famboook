"use client";

import { useEffect, useRef, useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, CheckCircle2, UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import { useAddFamilyMember } from "@/lib/api/families";
import { useRelationshipTypes } from "@/lib/api/reference";
import { ApiError } from "@/lib/api/client";
import {
  addFamilyMemberSchema,
  memberApiFieldToFormField,
  relationshipGenderDefault,
  toAddFamilyMemberPayload,
  type AddFamilyMemberValues,
} from "@/lib/schemas/add-family-member";
import { MaritalStatusSelect } from "@/components/shared/marital-status-select";

// Relationship is reset along with personal data so the next member never
// silently inherits the previous member's relationship.
const EMPTY_VALUES: Partial<AddFamilyMemberValues> = {
  relationshipTypeId: undefined,
  gender: "MALE",
  maritalStatus: "UNKNOWN",
  fullName: "",
  nationalId: "",
  birthDate: "",
  mobile: "",
  alternateMobile: "",
};

export function AddMemberDialog({ familyCode }: { familyCode: string }) {
  const [open, setOpen] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [justAdded, setJustAdded] = useState<string | null>(null);
  const addMember = useAddFamilyMember(familyCode);
  const { data: relationshipTypesData, isLoading: relationshipTypesLoading } =
    useRelationshipTypes();

  // The HEAD relationship is never valid here: this endpoint never
  // creates a household head (docs task B) — the backend rejects it too.
  const relationshipOptions = (relationshipTypesData?.data ?? []).filter(
    (type) => type.code !== "HEAD"
  );

  const confirmationTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Synchronous in-flight guard: isPending only flips after async zod
  // validation resolves, so a fast double click could otherwise send two
  // POSTs before the buttons disable.
  const submitting = useRef(false);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setValue,
    setFocus,
    setError,
    formState: { errors },
  } = useForm<AddFamilyMemberValues>({
    resolver: zodResolver(addFamilyMemberSchema),
    defaultValues: EMPTY_VALUES,
  });

  useEffect(() => {
    return () => {
      if (confirmationTimeout.current) clearTimeout(confirmationTimeout.current);
    };
  }, []);

  function handleRelationshipChange(value: string, onChange: (v: number) => void) {
    const id = Number(value);
    onChange(id);

    const code = relationshipOptions.find((type) => type.id === id)?.code;
    if (code && relationshipGenderDefault[code]) {
      setValue("gender", relationshipGenderDefault[code]);
    }
  }

  function closeDialog() {
    setOpen(false);
    reset(EMPTY_VALUES);
    setSubmitError(null);
    setJustAdded(null);
  }

  function submit(andAddAnother: boolean) {
    if (submitting.current) return;
    submitting.current = true;

    void handleSubmit(
      (values) => {
        setSubmitError(null);
        setJustAdded(null);

        // The form is only reset after the API confirms success.
        addMember.mutate(toAddFamilyMemberPayload(values), {
          onSettled: () => {
            submitting.current = false;
          },
          onSuccess: () => {
            if (!andAddAnother) {
              closeDialog();
              return;
            }

            reset(EMPTY_VALUES);

            setJustAdded(values.fullName);
            if (confirmationTimeout.current) clearTimeout(confirmationTimeout.current);
            confirmationTimeout.current = setTimeout(() => setJustAdded(null), 4000);

            // Relationship is the first field and was just cleared.
            // Deferred so focus lands after the buttons re-enable.
            requestAnimationFrame(() => setFocus("relationshipTypeId"));
          },
          onError: (error) => {
            if (error instanceof ApiError && error.status === 422) {
              const validationErrors = error.validationErrors;
              if (validationErrors) {
                for (const [apiField, messages] of Object.entries(validationErrors)) {
                  const formField = memberApiFieldToFormField[apiField];
                  if (formField && messages[0]) {
                    setError(formField, { type: "server", message: messages[0] });
                  }
                }
              }
              setSubmitError(
                error.message422 ?? "توجد أخطاء في البيانات المُدخلة."
              );
              return;
            }

            if (error instanceof ApiError && error.status === 401) {
              setSubmitError("انتهت جلسة الدخول. يرجى تسجيل الدخول مجددًا.");
              return;
            }

            if (error instanceof ApiError && error.status === 403) {
              setSubmitError("لا تملك صلاحية إضافة فرد لهذه الأسرة.");
              return;
            }

            setSubmitError("تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
          },
        });
      },
      () => {
        // Client-side validation failed: no request was sent.
        submitting.current = false;
      }
    )();
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (next) {
          setOpen(true);
        } else if (!addMember.isPending) {
          // Not while a request is in flight: its success handler would
          // act on a dialog the user already dismissed.
          closeDialog();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm">
          <UserPlus className="size-4" />
          إضافة فرد
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إضافة فرد إلى الأسرة</DialogTitle>
          <DialogDescription>
            أدخل البيانات الأساسية للفرد الجديد.
          </DialogDescription>
        </DialogHeader>

        {submitError && (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر إضافة الفرد</AlertTitle>
            <AlertDescription>{submitError}</AlertDescription>
          </Alert>
        )}

        {justAdded && (
          <Alert>
            <CheckCircle2 className="size-4" />
            <AlertTitle>تمت الإضافة</AlertTitle>
            <AlertDescription>
              تمت إضافة &quot;{justAdded}&quot; بنجاح. يمكنك إضافة فرد آخر.
            </AlertDescription>
          </Alert>
        )}

        <form
          id="add-member-form"
          onSubmit={(e) => e.preventDefault()}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="member-relationshipTypeId">صلة القرابة</Label>
            <Controller
              control={control}
              name="relationshipTypeId"
              render={({ field }) => (
                <Select
                  value={field.value ? String(field.value) : ""}
                  onValueChange={(value) => handleRelationshipChange(value, field.onChange)}
                  disabled={relationshipTypesLoading}
                >
                  <SelectTrigger id="member-relationshipTypeId" ref={field.ref}>
                    <SelectValue
                      placeholder={
                        relationshipTypesLoading ? "جارٍ التحميل..." : "اختر صلة القرابة"
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {relationshipOptions.map((type) => (
                      <SelectItem key={type.id} value={String(type.id)}>
                        {type.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            {errors.relationshipTypeId && (
              <p className="text-xs text-destructive">
                {errors.relationshipTypeId.message}
              </p>
            )}
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="member-fullName">الاسم الكامل</Label>
            <Input id="member-fullName" {...register("fullName")} />
            {errors.fullName && (
              <p className="text-xs text-destructive">
                {errors.fullName.message}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-gender">الجنس</Label>
              <Controller
                control={control}
                name="gender"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="member-gender">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="MALE">ذكر</SelectItem>
                      <SelectItem value="FEMALE">أنثى</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-maritalStatus">الحالة الاجتماعية</Label>
              <Controller
                control={control}
                name="maritalStatus"
                render={({ field }) => (
                  <MaritalStatusSelect id="member-maritalStatus" value={field.value} onChange={field.onChange} />
                )}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-birthDate">تاريخ الميلاد</Label>
              <Input
                id="member-birthDate"
                type="date"
                dir="ltr"
                className="text-end"
                {...register("birthDate")}
              />
              {errors.birthDate && (
                <p className="text-xs text-destructive">
                  {errors.birthDate.message}
                </p>
              )}
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="member-nationalId">
              رقم الهوية الوطنية{" "}
              <span className="text-xs font-normal text-muted-foreground">
                (اختياري)
              </span>
            </Label>
            <Input
              id="member-nationalId"
              dir="ltr"
              className="text-end"
              {...register("nationalId")}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-mobile">
                رقم الجوال{" "}
                <span className="text-xs font-normal text-muted-foreground">
                  (اختياري)
                </span>
              </Label>
              <Input
                id="member-mobile"
                dir="ltr"
                className="text-end"
                {...register("mobile")}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-alternateMobile">
                رقم جوال بديل{" "}
                <span className="text-xs font-normal text-muted-foreground">
                  (اختياري)
                </span>
              </Label>
              <Input
                id="member-alternateMobile"
                dir="ltr"
                className="text-end"
                {...register("alternateMobile")}
              />
            </div>
          </div>
        </form>

        <DialogFooter className="flex-col gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={closeDialog}
            disabled={addMember.isPending}
          >
            إلغاء
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => submit(true)}
            disabled={addMember.isPending}
          >
            {addMember.isPending ? "جارٍ الإضافة..." : "حفظ وإضافة فرد آخر"}
          </Button>
          <Button type="button" onClick={() => submit(false)} disabled={addMember.isPending}>
            {addMember.isPending ? "جارٍ الإضافة..." : "حفظ"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
