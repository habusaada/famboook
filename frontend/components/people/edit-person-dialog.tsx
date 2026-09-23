"use client";

import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, Pencil } from "lucide-react";
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
import { useUpdatePerson } from "@/lib/api/people";
import { ApiError } from "@/lib/api/client";
import {
  editPersonSchema,
  personApiFieldToFormField,
  toUpdatePersonPayload,
  type EditPersonValues,
} from "@/lib/schemas/edit-person";
import type { PersonDetail } from "@/lib/types/api/person";

export function EditPersonDialog({ person }: { person: PersonDetail }) {
  const [open, setOpen] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const updatePerson = useUpdatePerson(person.person_code);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<EditPersonValues>({
    resolver: zodResolver(editPersonSchema),
    defaultValues: {
      fullName: person.full_name,
      gender: person.gender,
      birthDate: person.birth_date ?? "",
      mobile: person.mobile ?? "",
      alternateMobile: person.alternate_mobile ?? "",
    },
  });

  function handleOpenChange(next: boolean) {
    setOpen(next);
    if (next) {
      reset({
        fullName: person.full_name,
        gender: person.gender,
        birthDate: person.birth_date ?? "",
        mobile: person.mobile ?? "",
        alternateMobile: person.alternate_mobile ?? "",
      });
      setSubmitError(null);
    }
  }

  function onSubmit(values: EditPersonValues) {
    setSubmitError(null);

    updatePerson.mutate(toUpdatePersonPayload(values), {
      onSuccess: () => setOpen(false),
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          const validationErrors = error.validationErrors;
          if (validationErrors) {
            for (const [apiField, messages] of Object.entries(validationErrors)) {
              const formField = personApiFieldToFormField[apiField];
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
          setSubmitError("لا تملك صلاحية تعديل بيانات هذا الشخص.");
          return;
        }

        setSubmitError("تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
      },
    });
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <Pencil className="size-4" />
          تعديل
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تعديل بيانات الشخص</DialogTitle>
          <DialogDescription>
            تعديل البيانات الأساسية فقط. لا يشمل تغيير رب الأسرة أو نقل
            العضوية.
          </DialogDescription>
        </DialogHeader>

        {submitError && (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر حفظ التعديلات</AlertTitle>
            <AlertDescription>{submitError}</AlertDescription>
          </Alert>
        )}

        <form
          id="edit-person-form"
          onSubmit={handleSubmit(onSubmit)}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="edit-fullName">الاسم الكامل</Label>
            <Input id="edit-fullName" {...register("fullName")} />
            {errors.fullName && (
              <p className="text-xs text-destructive">
                {errors.fullName.message}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-gender">الجنس</Label>
              <Controller
                control={control}
                name="gender"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="edit-gender">
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
              <Label htmlFor="edit-birthDate">تاريخ الميلاد</Label>
              <Input
                id="edit-birthDate"
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
            <Label htmlFor="edit-mobile">رقم الجوال</Label>
            <Input
              id="edit-mobile"
              dir="ltr"
              className="text-end"
              {...register("mobile")}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="edit-alternateMobile">رقم جوال بديل</Label>
            <Input
              id="edit-alternateMobile"
              dir="ltr"
              className="text-end"
              {...register("alternateMobile")}
            />
          </div>
        </form>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)}>
            إلغاء
          </Button>
          <Button
            type="submit"
            form="edit-person-form"
            disabled={updatePerson.isPending}
          >
            {updatePerson.isPending ? "جارٍ الحفظ..." : "حفظ التعديلات"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
