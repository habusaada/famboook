"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, CheckCircle2 } from "lucide-react";
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
import {
  familyRegistrationSchema,
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
  const [savedState, setSavedState] = useState<"draft" | "submitted" | null>(
    null
  );

  const {
    register,
    control,
    handleSubmit,
    formState: { errors },
  } = useForm<FamilyRegistrationValues>({
    resolver: zodResolver(familyRegistrationSchema),
    defaultValues: {
      registrationSource: "MANUAL_ENTRY",
      headGender: "MALE",
    },
  });

  function onSaveDraft(values: FamilyRegistrationValues) {
    // No backend connection yet — this is a visual mock only.
    console.log("Save draft (mock):", values);
    setSavedState("draft");
  }

  function onSubmitFamily(values: FamilyRegistrationValues) {
    console.log("Register family (mock):", values);
    setSavedState("submitted");
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
        {savedState && (
          <Alert className="mb-5">
            <CheckCircle2 className="size-4" />
            <AlertTitle>
              {savedState === "draft" ? "تم حفظ المسودة" : "تم إرسال البيانات"}
            </AlertTitle>
            <AlertDescription>
              هذه معاينة مرئية فقط — لا يوجد اتصال بالخادم بعد، ولم يتم حفظ أي
              بيانات فعلياً.
            </AlertDescription>
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
        <div className="mx-auto flex max-w-3xl flex-col-reverse gap-3 sm:flex-row sm:justify-end">
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
            onClick={handleSubmit(onSaveDraft)}
          >
            حفظ كمسودة
          </Button>
          <Button type="button" onClick={handleSubmit(onSubmitFamily)}>
            حفظ ومتابعة
          </Button>
        </div>
      </div>
    </div>
  );
}
