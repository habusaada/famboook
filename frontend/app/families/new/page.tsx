"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, ArrowRight, Clock } from "lucide-react";
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
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useRegisterFamily } from "@/lib/api/families";
import { ApiError } from "@/lib/api/client";
import {
  apiFieldToFormField,
  familyRegistrationSchema,
  toRegisterFamilyPayload,
  type FamilyRegistrationValues,
} from "@/lib/schemas/family-registration";

const registrationSourceOptions = [
  { value: "PAPER_FORM", label: "نموذج ورقي" },
  { value: "MANUAL_ENTRY", label: "إدخال يدوي" },
  { value: "IMPORT", label: "استيراد بيانات" },
  { value: "VERIFIED_SOURCE", label: "مصدر موثّق" },
];

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

export default function NewFamilyPage() {
  const router = useRouter();
  const [submitError, setSubmitError] = useState<string | null>(null);
  const registerFamily = useRegisterFamily();

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FamilyRegistrationValues>({
    resolver: zodResolver(familyRegistrationSchema),
    defaultValues: {
      registrationSource: "MANUAL_ENTRY",
      headGender: "MALE",
    },
  });

  function onSubmitFamily(values: FamilyRegistrationValues) {
    setSubmitError(null);

    registerFamily.mutate(toRegisterFamilyPayload(values), {
      onSuccess: (response) => {
        router.push(`/families/${response.data.family_code}`);
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          const validationErrors = error.validationErrors;
          if (validationErrors) {
            for (const [apiField, messages] of Object.entries(validationErrors)) {
              const formField = apiFieldToFormField[apiField];
              if (formField && messages[0]) {
                setError(formField, { type: "server", message: messages[0] });
              }
            }
          }
          setSubmitError(
            error.message422 ?? "توجد أخطاء في البيانات المُدخلة، الرجاء مراجعتها."
          );
          return;
        }

        if (error instanceof ApiError && error.status === 401) {
          setSubmitError("انتهت الجلسة أو لم يتم تسجيل الدخول.");
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setSubmitError("لا تملك صلاحية تسجيل أسرة جديدة.");
          return;
        }

        setSubmitError("تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
      },
    });
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <Button
          type="button"
          variant="ghost"
          className="w-fit gap-1.5 ps-2 text-muted-foreground"
          onClick={() => router.push("/families")}
        >
          <ArrowRight className="size-4" />
          العودة إلى سجل العائلات
        </Button>
        <h2 className="text-xl font-semibold tracking-tight">
          إضافة أسرة جديدة
        </h2>
        <p className="text-sm text-muted-foreground">
          تسجيل البيانات الأساسية للأسرة ورب الأسرة والسكن الحالي
        </p>
      </div>

      <div className="mx-auto w-full max-w-3xl">
        {submitError && (
          <Alert variant="destructive" className="mb-5">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر إتمام التسجيل</AlertTitle>
            <AlertDescription>{submitError}</AlertDescription>
          </Alert>
        )}

        <form className="flex flex-col gap-5">
          <Card>
            <CardHeader>
              <CardTitle>معلومات الأسرة الأساسية</CardTitle>
              <CardDescription>بيانات التسجيل ومصدره</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="registrationDate">
                  تاريخ التسجيل
                </FieldLabel>
                <Input
                  id="registrationDate"
                  type="date"
                  dir="ltr"
                  className="text-end"
                  {...register("registrationDate")}
                />
                {errors.registrationDate && (
                  <p className="text-xs text-destructive">
                    {errors.registrationDate.message}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="registrationSource">
                  مصدر التسجيل
                </FieldLabel>
                <Controller
                  control={control}
                  name="registrationSource"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="registrationSource">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {registrationSourceOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="paperFormNo" optional>
                  رقم النموذج الورقي
                </FieldLabel>
                <Input id="paperFormNo" {...register("paperFormNo")} />
              </div>

              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <FieldLabel htmlFor="notes" optional>
                  ملاحظات
                </FieldLabel>
                <Textarea id="notes" rows={3} {...register("notes")} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>بيانات رب الأسرة</CardTitle>
              <CardDescription>المعلومات الأساسية لرب الأسرة</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <FieldLabel htmlFor="headFullName">الاسم الكامل</FieldLabel>
                <Input id="headFullName" {...register("headFullName")} />
                {errors.headFullName && (
                  <p className="text-xs text-destructive">
                    {errors.headFullName.message}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="headNationalId" optional>
                  رقم الهوية الوطنية
                </FieldLabel>
                <Input
                  id="headNationalId"
                  dir="ltr"
                  className="text-end"
                  {...register("headNationalId")}
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="headGender">الجنس</FieldLabel>
                <Controller
                  control={control}
                  name="headGender"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="headGender">
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
                <FieldLabel htmlFor="headBirthDate">تاريخ الميلاد</FieldLabel>
                <Input
                  id="headBirthDate"
                  type="date"
                  dir="ltr"
                  className="text-end"
                  {...register("headBirthDate")}
                />
                {errors.headBirthDate && (
                  <p className="text-xs text-destructive">
                    {errors.headBirthDate.message}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="headMobile" optional>
                  رقم الجوال
                </FieldLabel>
                <Input
                  id="headMobile"
                  dir="ltr"
                  className="text-end"
                  {...register("headMobile")}
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>السكن الحالي</CardTitle>
              <CardDescription>موقع إقامة الأسرة الحالي</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="governorate">المحافظة</FieldLabel>
                <Input id="governorate" {...register("governorate")} />
                {errors.governorate && (
                  <p className="text-xs text-destructive">
                    {errors.governorate.message}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="city">المدينة</FieldLabel>
                <Input id="city" {...register("city")} />
                {errors.city && (
                  <p className="text-xs text-destructive">
                    {errors.city.message}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="area" optional>
                  المنطقة
                </FieldLabel>
                <Input id="area" {...register("area")} />
              </div>

              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="displacementStatus" optional>
                  حالة النزوح
                </FieldLabel>
                <Input
                  id="displacementStatus"
                  {...register("displacementStatus")}
                />
              </div>

              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <FieldLabel htmlFor="addressText" optional>
                  العنوان التفصيلي
                </FieldLabel>
                <Textarea
                  id="addressText"
                  rows={2}
                  {...register("addressText")}
                />
              </div>
            </CardContent>
          </Card>
        </form>
      </div>

      <div className="sticky bottom-0 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur supports-backdrop-filter:bg-background/80 md:-mx-5 md:px-5">
        <div className="mx-auto flex max-w-3xl flex-col-reverse gap-3 sm:flex-row sm:justify-end sm:items-center">
          <Button
            type="button"
            variant="outline"
            onClick={() => router.push("/families")}
          >
            إلغاء / العودة
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled
            title="حفظ المسودات غير متاح بعد — سيُضاف لاحقًا"
            className="gap-1.5"
          >
            <Clock className="size-3.5" />
            حفظ كمسودة (قريبًا)
          </Button>
          <Button
            type="button"
            onClick={handleSubmit(onSubmitFamily)}
            disabled={registerFamily.isPending}
          >
            {registerFamily.isPending ? "جارٍ الحفظ..." : "حفظ ومتابعة"}
          </Button>
        </div>
      </div>
    </div>
  );
}
