"use client";

import {
  DUPLICATE_NATIONAL_ID_MESSAGE,
  NationalIdDuplicateNotice,
} from "@/components/shared/national-id-duplicate";
import { RequirePermission } from "@/components/auth/require-permission";
import { useCallback, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { MaritalStatusSelect } from "@/components/shared/marital-status-select";
import { ClanBranchFields } from "@/components/shared/clan-branch-fields";
import { Controller, useForm, useWatch, type FieldErrors } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import {
  AlertCircle,
  CheckCircle2,
  ChevronLeft,
  CircleHelp,
  Home,
  Info,
  Loader2,
  MapPin,
  Phone,
  UserRound,
  UserRoundPlus,
  type LucideIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { AppCard } from "@/components/shared/app-card";
import { IconBox } from "@/components/shared/icon-box";
import { PageHeader } from "@/components/shared/page-layout";
import { useRegisterFamily } from "@/lib/api/families";
import { checkNationalId, duplicateMatches } from "@/lib/api/people";
import { ApiError } from "@/lib/api/client";
import type { NationalIdMatch } from "@/lib/types/api/person";
import {
  apiFieldToFormField,
  familyRegistrationSchema,
  toRegisterFamilyPayload,
  type FamilyRegistrationValues,
} from "@/lib/schemas/family-registration";
import { todayIso } from "@/lib/schemas/assessment";
import { cn } from "@/lib/utils";

const registrationSourceOptions = [
  { value: "PAPER_FORM", label: "نموذج ورقي" },
  { value: "MANUAL_ENTRY", label: "إدخال يدوي" },
  { value: "IMPORT", label: "استيراد بيانات" },
  { value: "VERIFIED_SOURCE", label: "مصدر موثّق" },
];

// Section index (frontend only): which form fields belong to which section,
// used for the error markers — never a completeness score.
const SECTIONS: { id: string; title: string; fields: (keyof FamilyRegistrationValues)[] }[] = [
  { id: "family", title: "الأسرة والانتماء", fields: ["registrationDate", "registrationSource", "paperFormNo", "notes", "clanCode", "branchCode"] },
  { id: "head", title: "رب الأسرة", fields: ["headFullName", "headNationalId", "headGender", "headMaritalStatus", "headBirthDate"] },
  { id: "contact", title: "التواصل", fields: ["headMobile", "headAlternateMobile", "headAlternateMobileOwnerRelation"] },
  { id: "residence", title: "السكن والنزوح", fields: ["governorate", "city", "area", "neighborhood", "addressText", "originalResidenceText", "isDisplaced", "displacementLocationText"] },
];

// ------------------------------------------------------------------ field primitives

function FieldLabel({ htmlFor, required, hint, children }: { htmlFor: string; required?: boolean; hint?: string; children: React.ReactNode }) {
  return (
    <Label htmlFor={htmlFor} className="text-[13px] font-medium text-foreground">
      {children}
      {required && (
        <span className="text-danger" aria-hidden>
          *
        </span>
      )}
      {required && <span className="sr-only">(مطلوب)</span>}
      {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
    </Label>
  );
}

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null;
  return (
    <p id={id} className="flex items-center gap-1 text-xs text-danger" role="alert">
      <AlertCircle className="size-3.5 shrink-0" aria-hidden />
      {message}
    </p>
  );
}

function Field({ className, children }: { className?: string; children: React.ReactNode }) {
  return <div className={cn("flex min-w-0 flex-col gap-1.5", className)}>{children}</div>;
}

const control = "h-10 bg-surface-2 focus-visible:bg-surface-1";

function Section({ id, icon, title, description, children }: { id: string; icon: LucideIcon; title: string; description?: string; children: React.ReactNode }) {
  return (
    <AppCard padded={false} aria-labelledby={`${id}-title`} id={`section-${id}`} className="scroll-mt-4" data-form-section={id}>
      <div className="flex items-start gap-3 px-4 pt-4 sm:px-5">
        <IconBox icon={icon} size="sm" />
        <div className="flex min-w-0 flex-col gap-0.5">
          <h2 id={`${id}-title`} className="text-base leading-snug font-semibold">
            {title}
          </h2>
          {description && <p className="text-[13px] text-muted-foreground">{description}</p>}
        </div>
      </div>
      <div className="px-4 pt-4 pb-5 sm:px-5">{children}</div>
    </AppCard>
  );
}

/** Two- or three-way choice as a keyboard-native radio group (arrow keys). */
function Segmented({
  name,
  label,
  value,
  options,
  onChange,
  required,
  invalid,
  describedBy,
}: {
  name: string;
  label: string;
  value: string;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
  required?: boolean;
  invalid?: boolean;
  describedBy?: string;
}) {
  return (
    <fieldset className="flex min-w-0 flex-col gap-1.5" aria-invalid={invalid || undefined} aria-describedby={describedBy}>
      <legend className="mb-1.5 text-[13px] font-medium text-foreground">
        {label}
        {required && (
          <span className="text-danger" aria-hidden>
            {" "}*
          </span>
        )}
        {required && <span className="sr-only">(مطلوب)</span>}
      </legend>
      <div className={cn("flex gap-1 rounded-lg bg-surface-2 p-1", invalid && "ring-1 ring-danger/60")} data-segmented={name}>
        {options.map((o) => {
          const selected = value === o.value;
          return (
            <label
              key={o.value || "none"}
              className={cn(
                "flex h-8 flex-1 cursor-pointer items-center justify-center rounded-control border px-2 text-[13px] whitespace-nowrap transition-colors",
                "has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring",
                selected ? "border-brand-700/30 bg-surface-1 font-semibold text-brand-800 shadow-e1" : "border-transparent text-muted-foreground hover:text-foreground"
              )}
            >
              <input type="radio" name={name} value={o.value} checked={selected} onChange={() => onChange(o.value)} className="sr-only" />
              {o.label}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

// ------------------------------------------------------------------ National ID check

type IdentityState = "idle" | "checking" | "clear" | "duplicate" | "unavailable";

/**
 * The existing exact National ID pre-check (POST, AUTH-ADR-058), with its
 * status made visible. The server still refuses a duplicate on submit.
 */
function useIdentityCheck() {
  const [state, setState] = useState<IdentityState>("idle");
  const [matches, setMatches] = useState<NationalIdMatch[]>([]);
  const last = useRef("");

  const check = useCallback(async (value: string | undefined) => {
    const nationalId = value?.trim() ?? "";
    last.current = nationalId;
    if (!nationalId) {
      setState("idle");
      setMatches([]);
      return;
    }
    setState("checking");
    try {
      const found = await checkNationalId(nationalId);
      if (last.current !== nationalId) return;
      setMatches(found);
      setState(found.length > 0 ? "duplicate" : "clear");
    } catch {
      if (last.current !== nationalId) return;
      setMatches([]);
      setState("unavailable");
    }
  }, []);

  const fromError = useCallback((error: unknown): boolean => {
    const found = duplicateMatches(error);
    if (found === null) return false;
    setMatches(found);
    setState("duplicate");
    return true;
  }, []);

  const clear = useCallback(() => {
    last.current = "";
    setMatches([]);
    setState("idle");
  }, []);

  return { state, matches, check, fromError, clear };
}

function IdentityStatus({ state }: { state: IdentityState }) {
  if (state === "idle" || state === "duplicate") return null;
  const map = {
    checking: { icon: Loader2, text: "جارٍ التحقق من عدم تسجيل الهوية مسبقًا…", tone: "text-muted-foreground", spin: true },
    clear: { icon: CheckCircle2, text: "لا يوجد شخص مسجّل بهذه الهوية.", tone: "text-success", spin: false },
    unavailable: { icon: CircleHelp, text: "تعذّر التحقق الآن؛ سيتحقق الخادم من التكرار عند الحفظ.", tone: "text-muted-foreground", spin: false },
  } as const;
  const s = map[state];
  return (
    <p className={cn("flex items-center gap-1.5 text-xs", s.tone)} aria-live="polite" data-identity-state={state}>
      <s.icon className={cn("size-3.5 shrink-0", s.spin && "animate-spin")} aria-hidden />
      {s.text}
    </p>
  );
}

// ------------------------------------------------------------------ page

export default function NewFamilyPage() {
  return (
    <RequirePermission permission="family.create">
      <RegisterFamilyForm />
    </RequirePermission>
  );
}

function sectionHasError(errors: FieldErrors<FamilyRegistrationValues>, fields: (keyof FamilyRegistrationValues)[]) {
  return fields.some((f) => Boolean(errors[f]));
}

function RegisterFamilyForm() {
  const router = useRouter();
  const [submitError, setSubmitError] = useState<string | null>(null);
  const registerFamily = useRegisterFamily();
  const identity = useIdentityCheck();

  const {
    register,
    control: formControl,
    handleSubmit,
    setError,
    setValue,
    formState: { errors },
  } = useForm<FamilyRegistrationValues>({
    resolver: zodResolver(familyRegistrationSchema),
    // Initial values only (a new registration): today's local date, and no
    // gender — it is required and must be chosen explicitly.
    defaultValues: {
      registrationDate: todayIso(),
      registrationSource: "MANUAL_ENTRY",
      headMaritalStatus: "UNKNOWN",
      clanCode: "",
      branchCode: "",
    },
  });

  const hasAlternateMobile = Boolean(useWatch({ control: formControl, name: "headAlternateMobile" })?.trim());
  const displaced = useWatch({ control: formControl, name: "isDisplaced" });
  const isDisplaced = displaced === "YES";
  const clanCode = useWatch({ control: formControl, name: "clanCode" }) ?? "";
  const branchCode = useWatch({ control: formControl, name: "branchCode" }) ?? "";
  const setClanCode = useCallback(
    (code: string) => setValue("clanCode", code, { shouldValidate: Boolean(code) }),
    [setValue]
  );

  const errorProps = (name: keyof FamilyRegistrationValues, required = false) => ({
    "aria-invalid": errors[name] ? true : undefined,
    "aria-describedby": errors[name] ? `${name}-error` : undefined,
    "aria-required": required || undefined,
  });

  function onSubmitFamily(values: FamilyRegistrationValues) {
    setSubmitError(null);

    registerFamily.mutate(toRegisterFamilyPayload(values), {
      onSuccess: (response) => {
        router.push(`/families/${response.data.family_code}`);
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          // Existing Person with this National ID: nothing was created.
          if (identity.fromError(error)) {
            setError("headNationalId", { type: "server", message: DUPLICATE_NATIONAL_ID_MESSAGE });
            setSubmitError(DUPLICATE_NATIONAL_ID_MESSAGE);
            return;
          }
          const validationErrors = error.validationErrors;
          if (validationErrors) {
            for (const [apiField, messages] of Object.entries(validationErrors)) {
              const formField = apiFieldToFormField[apiField];
              if (formField && messages[0]) {
                setError(formField, { type: "server", message: messages[0] });
              }
            }
          }
          setSubmitError(error.message422 ?? "توجد أخطاء في البيانات المُدخلة، الرجاء مراجعتها.");
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

  const pending = registerFamily.isPending;
  const submit = handleSubmit(onSubmitFamily);

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="مسار الصفحة" className="flex items-center gap-1 text-[13px] text-muted-foreground">
        <Link href="/families" className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
          الأسر
        </Link>
        <ChevronLeft className="size-3.5" aria-hidden />
        <span aria-current="page" className="font-medium text-foreground">
          تسجيل أسرة جديدة
        </span>
      </nav>

      <PageHeader title="تسجيل أسرة جديدة" description="إدخال البيانات الأساسية للأسرة ورب الأسرة والسكن الحالي." />

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="flex items-start gap-1.5 text-[13px] text-muted-foreground" data-registration-note>
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          يُنشأ ملف الأسرة ورب الأسرة معًا، ويُولَّد رقما الأسرة والفرد تلقائيًا. يُضاف بقية الأفراد من ملف الأسرة بعد الحفظ.
        </p>
        <p className="shrink-0 text-xs text-muted-foreground">
          <span className="text-danger" aria-hidden>
            *
          </span>{" "}
          حقل مطلوب
        </p>
      </div>

      {/* Section index: jump links, with a marker on sections that have errors. */}
      <nav aria-label="أقسام النموذج" className="-mx-1 overflow-x-auto px-1">
        <ol className="flex gap-1.5" data-section-index>
          {SECTIONS.map((s, i) => {
            const bad = sectionHasError(errors, s.fields);
            return (
              <li key={s.id} className="shrink-0">
                <a
                  href={`#section-${s.id}`}
                  className={cn(
                    "flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] transition-colors focus-visible:outline-2 focus-visible:outline-ring",
                    bad ? "border-danger/40 bg-danger-soft text-danger" : "border-stroke-subtle bg-surface-1 text-muted-foreground hover:text-foreground"
                  )}
                  data-section-link={s.id}
                  data-has-error={bad || undefined}
                >
                  <span className="tabular-nums">{i + 1}</span>
                  {s.title}
                  {bad && (
                    <>
                      <AlertCircle className="size-3.5" aria-hidden />
                      <span className="sr-only">(يحتوي على أخطاء)</span>
                    </>
                  )}
                </a>
              </li>
            );
          })}
        </ol>
      </nav>

      {submitError && (
        <Alert variant="destructive" data-submit-error>
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر إتمام التسجيل</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      {/* Enter never submits: registration happens only through the explicit action. */}
      <form className="flex flex-col gap-4" onSubmit={(e) => e.preventDefault()} noValidate>
        <Section id="family" icon={Home} title="الأسرة والانتماء" description="بيانات التسجيل، والعشيرة / العائلة (مطلوبة) والفرع (اختياري).">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 [&_[data-slot=select-trigger]]:h-10! [&_[data-slot=select-trigger]]:w-full [&_[data-slot=select-trigger]]:bg-surface-2">
            <Field>
              <FieldLabel htmlFor="registrationDate" required>
                تاريخ التسجيل
              </FieldLabel>
              <Input id="registrationDate" type="date" dir="ltr" className={cn(control, "text-end")} {...register("registrationDate")} {...errorProps("registrationDate", true)} />
              <FieldError id="registrationDate-error" message={errors.registrationDate?.message} />
            </Field>
            <Field>
              <FieldLabel htmlFor="registrationSource" required>
                مصدر التسجيل
              </FieldLabel>
              <Controller
                control={formControl}
                name="registrationSource"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="registrationSource" className={cn(control, "h-10! w-full")} aria-required>
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
            </Field>
            <Field>
              <FieldLabel htmlFor="paperFormNo">رقم النموذج الورقي</FieldLabel>
              <Input id="paperFormNo" dir="auto" className={control} {...register("paperFormNo")} />
            </Field>
            <ClanBranchFields
              idPrefix="register"
              clanCode={clanCode}
              branchCode={branchCode}
              onClanChange={setClanCode}
              onBranchChange={(code) => setValue("branchCode", code)}
              clanError={errors.clanCode?.message}
              branchError={errors.branchCode?.message}
              preselectSingleClan
            />
            <Field className="sm:col-span-2 xl:col-span-3">
              <FieldLabel htmlFor="notes">ملاحظات</FieldLabel>
              <Textarea id="notes" rows={2} className="min-h-10 bg-surface-2 focus-visible:bg-surface-1" {...register("notes")} />
            </Field>
          </div>
        </Section>

        <Section id="head" icon={UserRound} title="رب الأسرة" description="يُسجَّل هذا الشخص فردًا في الأسرة ورب الأسرة الحالي.">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 [&_[data-slot=select-trigger]]:h-10! [&_[data-slot=select-trigger]]:w-full [&_[data-slot=select-trigger]]:bg-surface-2">
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="headFullName" required>
                الاسم الكامل
              </FieldLabel>
              <Input id="headFullName" className={cn(control, "text-[15px]")} autoComplete="off" {...register("headFullName")} {...errorProps("headFullName", true)} />
              <FieldError id="headFullName-error" message={errors.headFullName?.message} />
            </Field>

            {/* Identity: visually distinct, LTR, numeric keypad, exact duplicate pre-check on blur. */}
            <Field className="rounded-lg border border-stroke-subtle bg-surface-2/70 p-3 sm:row-span-2 xl:row-span-1">
              <FieldLabel htmlFor="headNationalId" hint="(اختياري)">
                رقم الهوية الوطنية
              </FieldLabel>
              <Input
                id="headNationalId"
                dir="ltr"
                inputMode="numeric"
                autoComplete="off"
                className="h-10 bg-surface-1 text-end text-[15px] font-medium tracking-wider tabular-nums"
                {...register("headNationalId", {
                  onChange: () => identity.clear(),
                  onBlur: (event) => void identity.check(event.target.value),
                })}
                {...errorProps("headNationalId")}
              />
              <IdentityStatus state={identity.state} />
              <FieldError id="headNationalId-error" message={errors.headNationalId?.message} />
            </Field>

            <Controller
              control={formControl}
              name="headGender"
              render={({ field }) => (
                <div className="flex min-w-0 flex-col gap-1.5">
                  <Segmented
                    name="headGender"
                    label="الجنس"
                    required
                    value={field.value ?? ""}
                    onChange={field.onChange}
                    invalid={Boolean(errors.headGender)}
                    describedBy={errors.headGender ? "headGender-error" : undefined}
                    options={[
                      { value: "MALE", label: "ذكر" },
                      { value: "FEMALE", label: "أنثى" },
                    ]}
                  />
                  {/* The schema's enum message is generic; the rule itself is unchanged. */}
                  <FieldError id="headGender-error" message={errors.headGender ? "اختر الجنس" : undefined} />
                </div>
              )}
            />
            <Field>
              <FieldLabel htmlFor="headMaritalStatus">الحالة الاجتماعية</FieldLabel>
              <Controller
                control={formControl}
                name="headMaritalStatus"
                render={({ field }) => <MaritalStatusSelect id="headMaritalStatus" value={field.value} onChange={field.onChange} />}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="headBirthDate" hint="(اتركه فارغًا إن لم يكن معروفًا)">
                تاريخ الميلاد
              </FieldLabel>
              <Input id="headBirthDate" type="date" dir="ltr" className={cn(control, "text-end")} {...register("headBirthDate")} {...errorProps("headBirthDate")} />
              <FieldError id="headBirthDate-error" message={errors.headBirthDate?.message} />
            </Field>

            {identity.matches.length > 0 && (
              <div className="sm:col-span-2 xl:col-span-3" aria-live="assertive">
                <NationalIdDuplicateNotice matches={identity.matches} />
              </div>
            )}
          </div>
        </Section>

        <Section id="contact" icon={Phone} title="التواصل">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 [&_[data-slot=select-trigger]]:h-10! [&_[data-slot=select-trigger]]:w-full [&_[data-slot=select-trigger]]:bg-surface-2">
            <Field>
              <FieldLabel htmlFor="headMobile">رقم الجوال</FieldLabel>
              <Input id="headMobile" dir="ltr" inputMode="tel" autoComplete="off" className={cn(control, "text-end tabular-nums")} {...register("headMobile")} />
            </Field>
            <Field>
              <FieldLabel htmlFor="headAlternateMobile">رقم الجوال البديل</FieldLabel>
              <Input id="headAlternateMobile" dir="ltr" inputMode="tel" autoComplete="off" className={cn(control, "text-end tabular-nums")} {...register("headAlternateMobile")} />
            </Field>
            {hasAlternateMobile && (
              <Field>
                <FieldLabel htmlFor="headAlternateMobileOwnerRelation">صاحب الرقم البديل / صلته</FieldLabel>
                <Input
                  id="headAlternateMobileOwnerRelation"
                  placeholder="مثال: أحمد محمد – أخ"
                  className={control}
                  {...register("headAlternateMobileOwnerRelation")}
                  {...errorProps("headAlternateMobileOwnerRelation")}
                />
                <FieldError id="headAlternateMobileOwnerRelation-error" message={errors.headAlternateMobileOwnerRelation?.message} />
              </Field>
            )}
          </div>
        </Section>

        <Section
          id="residence"
          icon={MapPin}
          title="السكن والنزوح"
          description="سجّل ما تذكره الاستمارة فقط؛ الحقول غير المذكورة تُترك فارغة ولا تُقدَّر."
        >
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <fieldset className="flex min-w-0 flex-col gap-3 rounded-lg border border-stroke-subtle p-3 sm:p-4" data-residence-block="current">
              <legend className="px-1 text-[13px] font-semibold text-foreground">السكن الحالي</legend>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field>
                  <FieldLabel htmlFor="governorate">المحافظة</FieldLabel>
                  <Input id="governorate" className={control} {...register("governorate")} {...errorProps("governorate")} />
                  <FieldError id="governorate-error" message={errors.governorate?.message} />
                </Field>
                <Field>
                  <FieldLabel htmlFor="city">المدينة</FieldLabel>
                  <Input id="city" className={control} {...register("city")} {...errorProps("city")} />
                  <FieldError id="city-error" message={errors.city?.message} />
                </Field>
                <Field>
                  <FieldLabel htmlFor="area">المنطقة</FieldLabel>
                  <Input id="area" className={control} {...register("area")} />
                </Field>
                <Field>
                  <FieldLabel htmlFor="neighborhood">الحي</FieldLabel>
                  <Input id="neighborhood" className={control} {...register("neighborhood")} />
                </Field>
                <Field className="sm:col-span-2">
                  <FieldLabel htmlFor="addressText">العنوان التفصيلي</FieldLabel>
                  <Textarea id="addressText" rows={2} className="min-h-10 bg-surface-2 focus-visible:bg-surface-1" {...register("addressText")} />
                </Field>
              </div>
            </fieldset>

            <fieldset className="flex min-w-0 flex-col gap-3 rounded-lg border border-stroke-subtle p-3 sm:p-4" data-residence-block="displacement">
              <legend className="px-1 text-[13px] font-semibold text-foreground">النزوح</legend>
              <div className="grid grid-cols-1 gap-3">
                <Field>
                  <FieldLabel htmlFor="originalResidenceText" hint="(قبل النزوح — ليس مكان الميلاد)">
                    مكان السكن الأصلي
                  </FieldLabel>
                  <Input
                    id="originalResidenceText"
                    placeholder="مثال: بني سهيلا – خانيونس"
                    className={control}
                    {...register("originalResidenceText")}
                    {...errorProps("originalResidenceText")}
                  />
                  <FieldError id="originalResidenceText-error" message={errors.originalResidenceText?.message} />
                </Field>
                <Controller
                  control={formControl}
                  name="isDisplaced"
                  render={({ field }) => (
                    <Segmented
                      name="isDisplaced"
                      label="هل الأسرة نازحة حاليًا؟"
                      value={field.value ?? ""}
                      onChange={(value) => {
                        // "" = not stated on the form (null), never silently "NO".
                        field.onChange(value === "" ? undefined : value);
                        // Not displaced → no displacement location.
                        if (value !== "YES") setValue("displacementLocationText", "");
                      }}
                      options={[
                        { value: "YES", label: "نعم" },
                        { value: "NO", label: "لا" },
                        { value: "", label: "غير مذكور" },
                      ]}
                    />
                  )}
                />
                <FieldError id="isDisplaced-error" message={errors.isDisplaced?.message} />
                {isDisplaced && (
                  <Field>
                    <FieldLabel htmlFor="displacementLocationText">مكان النزوح الحالي</FieldLabel>
                    <Input
                      id="displacementLocationText"
                      placeholder="مثال: مواصي خانيونس"
                      className={control}
                      {...register("displacementLocationText")}
                      {...errorProps("displacementLocationText")}
                    />
                    <FieldError id="displacementLocationText-error" message={errors.displacementLocationText?.message} />
                  </Field>
                )}
              </div>
            </fieldset>
          </div>
        </Section>

        {/* Actions: what saving does, then cancel / register. */}
        <AppCard padded={false} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5" data-registration-actions>
          <p className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
            <UserRoundPlus className="size-4 shrink-0" aria-hidden />
            عند التسجيل يُنشأ ملف الأسرة ورب الأسرة، وتنتقل إلى ملف الأسرة.
          </p>
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
            <Button type="button" variant="ghost" onClick={() => router.push("/families")} disabled={pending}>
              إلغاء
            </Button>
            <Button type="button" onClick={submit} disabled={pending} data-register-submit>
              {pending ? (
                <>
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                  جارٍ التسجيل…
                </>
              ) : (
                <>
                  <UserRoundPlus className="size-4" aria-hidden />
                  تسجيل الأسرة
                </>
              )}
            </Button>
          </div>
        </AppCard>
      </form>
    </div>
  );
}
