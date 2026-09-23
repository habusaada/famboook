"use client";

import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, UserPlus } from "lucide-react";
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
import { ApiError } from "@/lib/api/client";
import {
  addFamilyMemberSchema,
  memberApiFieldToFormField,
  toAddFamilyMemberPayload,
  type AddFamilyMemberValues,
} from "@/lib/schemas/add-family-member";

export function AddMemberDialog({ familyCode }: { familyCode: string }) {
  const [open, setOpen] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const addMember = useAddFamilyMember(familyCode);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<AddFamilyMemberValues>({
    resolver: zodResolver(addFamilyMemberSchema),
    defaultValues: { gender: "MALE" },
  });

  function onSubmit(values: AddFamilyMemberValues) {
    setSubmitError(null);

    addMember.mutate(toAddFamilyMemberPayload(values), {
      onSuccess: () => {
        reset();
        setOpen(false);
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
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) {
          reset();
          setSubmitError(null);
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

        <form
          id="add-member-form"
          onSubmit={handleSubmit(onSubmit)}
          className="flex flex-col gap-4"
        >
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
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
          >
            إلغاء
          </Button>
          <Button type="submit" form="add-member-form" disabled={addMember.isPending}>
            {addMember.isPending ? "جارٍ الإضافة..." : "إضافة"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
