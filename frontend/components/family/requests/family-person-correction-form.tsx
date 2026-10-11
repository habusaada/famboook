"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, AlertTriangle, FileClock, Info, Loader2, RotateCw, Send } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { changeRequestErrorCode } from "@/lib/api/change-requests";
import { useFamilyChangeRequestTypesQuery, useSubmitFamilyChangeRequest } from "@/lib/api/family-change-requests";
import { type FamilyMember, isAccessFailure, useFamilyMembersQuery } from "@/lib/api/family-household";
import {
  PERSON_CORRECTION_DEFAULTS,
  PERSON_CORRECTION_FIELDS,
  type PersonCorrectionInput,
  type PersonCorrectionValues,
  personCorrectionChanges,
  personCorrectionFormValues,
  personCorrectionSchema,
  toPersonCorrectionData,
} from "@/lib/schemas/family-person-correction";
import { genderLabels } from "@/lib/utils/family-portal-labels";
import { MARITAL_STATUSES, maritalStatusLabels } from "@/lib/utils/marital-status";

const BACK = { href: "/family/requests/new", label: "طلب جديد" };
const TITLE = "تصحيح بيانات فرد";
const REVIEW_NOTICE = "سيُرسل طلبك للمراجعة، ولن تتغير بيانات السجل الرسمي إلا بعد اعتماد الطلب وتطبيقه.";

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-person-correction={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/requests">الانتقال إلى طلباتي</Link>
      </Button>
    </section>
  );
}

/** A safe Arabic message for a refused submission — never a raw error. */
function submitErrorMessage(error: unknown): string {
  switch (changeRequestErrorCode(error)) {
    case "CHANGE_REQUEST_ALREADY_OPEN":
      return "لدى أسرتك طلب تصحيح مفتوح لهذا الفرد. تابعه من «طلباتي».";
    case "CHANGE_REQUEST_NOT_FOUND":
      return "تعذّر العثور على هذا الفرد ضمن أفراد أسرتك. حدّث الصفحة وحاول مرة أخرى.";
    case "CHANGE_REQUEST_NOT_APPLICABLE":
      return "لم يعد هذا الفرد عضوًا نشطًا في أسرتك.";
    case "CHANGE_REQUEST_SUBMISSION_DISABLED":
      return "تقديم طلبات تحديث البيانات غير متاح حاليًا.";
    case "CHANGE_REQUEST_TYPE_UNAVAILABLE":
      return "هذا النوع من الطلبات غير متاح حاليًا.";
    case "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT":
      return "تعذّر إرسال الطلب. أعد الإرسال.";
  }
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت ثم أعد الإرسال؛ لن يُكرَّر طلبك.";
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا يمكنك تقديم هذا الطلب.";
    case 422:
      return "تحقق من البيانات المدخلة.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";
    default:
      return "حدث خطأ غير متوقع. أعد المحاولة لاحقًا؛ لن يُكرَّر طلبك.";
  }
}

function Required() {
  return (
    <span className="text-danger" aria-hidden>
      *
    </span>
  );
}

function CorrectionForm({ members }: { members: FamilyMember[] }) {
  const router = useRouter();
  const submitMutation = useSubmitFamilyChangeRequest();
  const form = useForm<PersonCorrectionInput, unknown, PersonCorrectionValues>({ resolver: zodResolver(personCorrectionSchema), defaultValues: PERSON_CORRECTION_DEFAULTS });
  const { register, control, setError, reset, formState, watch } = form;
  const errors = formState.errors;
  const [submitError, setSubmitError] = useState<string | null>(null);
  const memberRef = watch("memberRef");
  const member = members.find((m) => m.member_ref === memberRef) ?? null;
  // One client_reference per proposal; a retry of the same proposal re-sends it.
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);

  function selectMember(ref: string) {
    const next = members.find((m) => m.member_ref === ref);
    setSubmitError(null);
    if (next) reset(personCorrectionFormValues(next));
  }

  function send(values: PersonCorrectionValues) {
    if (sending.current || !member) return;
    setSubmitError(null);
    if (!personCorrectionChanges(member, values)) {
      setSubmitError("لم تغيّر أي بيانات. عدّل الحقول التي تحتاج إلى تصحيح ثم أرسل الطلب.");
      return;
    }
    const data = toPersonCorrectionData(values);
    const reason = values.reason.trim() === "" ? null : values.reason.trim();
    const key = JSON.stringify([data, reason]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    submitMutation.mutate(
      { type: "PERSON_CORRECTION", client_reference: attempt.current.reference, reason, data },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => router.push(`/family/requests/${outcome.id}`),
        onError: (error) => {
          if (changeRequestErrorCode(error) === "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT") attempt.current = null;
          const fieldErrors = error instanceof ApiError && error.status === 422 ? error.validationErrors : undefined;
          for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
            const target = PERSON_CORRECTION_FIELDS[field];
            if (target && messages[0]) setError(target, { type: "server", message: messages[0] });
          }
          setSubmitError(fieldErrors?.data?.[0] ?? submitErrorMessage(error));
        },
      }
    );
  }

  const pending = submitMutation.isPending;

  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} className="flex flex-col gap-4" noValidate aria-busy={pending} data-person-correction="form">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800" data-person-correction-notice>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>{REVIEW_NOTICE} لتصحيح رقم الهوية أو أرقام الجوال تواصل مع فريق السجل.</span>
      </p>

      <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
        <FieldLabel htmlFor="correction-member">
          الفرد <Required />
        </FieldLabel>
        <Controller
          control={control}
          name="memberRef"
          render={({ field }) => (
            <Select value={field.value === "" ? undefined : field.value} onValueChange={(value) => selectMember(value)} disabled={pending}>
              <SelectTrigger id="correction-member" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.memberRef)}>
                <SelectValue placeholder="اختر فردًا من أسرتك" />
              </SelectTrigger>
              <SelectContent>
                {members.map((m) => (
                  <SelectItem key={m.member_ref} value={m.member_ref}>
                    {m.full_name}
                    {m.is_household_head ? " (رب الأسرة)" : ""}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
        <FieldError message={errors.memberRef?.message} />
      </div>

      {member && (
        <>
          <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-labelledby="correction-current" data-person-correction-current>
            <h2 id="correction-current" className="mb-2 text-base font-semibold text-foreground">
              البيانات الحالية في السجل
            </h2>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
              <dt className="text-muted-foreground">الاسم الكامل</dt>
              <dd className="text-foreground">{member.full_name ?? "غير مسجّل"}</dd>
              <dt className="text-muted-foreground">تاريخ الميلاد</dt>
              <dd className="text-foreground tabular-nums">{member.birth_date ?? "غير مسجّل"}</dd>
              <dt className="text-muted-foreground">الجنس</dt>
              <dd className="text-foreground">{member.gender ? genderLabels[member.gender] : "غير مسجّل"}</dd>
              <dt className="text-muted-foreground">الحالة الاجتماعية</dt>
              <dd className="text-foreground">{member.marital_status ? maritalStatusLabels[member.marital_status] : "غير مسجّل"}</dd>
            </dl>
          </section>

          <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
            <legend className="px-1 text-base font-semibold text-foreground">التصحيح المطلوب</legend>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="correction-full-name">
                الاسم الكامل <Required />
              </FieldLabel>
              <Input id="correction-full-name" className="h-11 rounded-xl" autoComplete="off" aria-required aria-invalid={Boolean(errors.fullName)} {...register("fullName")} />
              <FieldError message={errors.fullName?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="correction-birth-date" optional>
                تاريخ الميلاد
              </FieldLabel>
              <Input id="correction-birth-date" type="date" dir="ltr" className="h-11 rounded-xl" max={new Date().toISOString().slice(0, 10)} aria-invalid={Boolean(errors.birthDate)} {...register("birthDate")} />
              <FieldError message={errors.birthDate?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="correction-gender">
                الجنس <Required />
              </FieldLabel>
              <Controller
                control={control}
                name="gender"
                render={({ field }) => (
                  <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                    <SelectTrigger id="correction-gender" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.gender)}>
                      <SelectValue placeholder="اختر" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="MALE">{genderLabels.MALE}</SelectItem>
                      <SelectItem value="FEMALE">{genderLabels.FEMALE}</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError message={errors.gender?.message} />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="correction-marital-status">
                الحالة الاجتماعية <Required />
              </FieldLabel>
              <Controller
                control={control}
                name="maritalStatus"
                render={({ field }) => (
                  <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                    <SelectTrigger id="correction-marital-status" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.maritalStatus)}>
                      <SelectValue placeholder="اختر" />
                    </SelectTrigger>
                    <SelectContent>
                      {MARITAL_STATUSES.map((status) => (
                        <SelectItem key={status} value={status}>
                          {maritalStatusLabels[status]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError message={errors.maritalStatus?.message} />
            </div>
          </fieldset>

          <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
            <FieldLabel htmlFor="correction-reason" optional>
              سبب الطلب
            </FieldLabel>
            <Textarea id="correction-reason" rows={3} className="rounded-xl" disabled={pending} aria-invalid={Boolean(errors.reason)} {...register("reason")} />
            <FieldError message={errors.reason?.message} />
          </div>
        </>
      )}

      {submitError && (
        <Alert variant="destructive" data-person-correction-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُرسل الطلب</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending || !member} data-person-correction-submit>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {pending ? "جارٍ الإرسال…" : "إرسال الطلب للمراجعة"}
      </Button>
    </form>
  );
}

/**
 * «تصحيح بيانات فرد» (PERSON_CORRECTION): the household head picks a member,
 * sees the current values and proposes corrections. Offered only while the
 * server lists PERSON_CORRECTION; members come from the household list
 * (member_ref only — never an id).
 */
export function FamilyPersonCorrectionForm() {
  const types = useFamilyChangeRequestTypesQuery();
  const available = Boolean(types.data?.meta.submission_enabled && types.data.data.some(({ type }) => type === "PERSON_CORRECTION"));
  const membersQuery = useFamilyMembersQuery();

  let body: React.ReactNode;
  const failed = types.isError ? types.error : membersQuery.isError ? membersQuery.error : null;
  if (failed) {
    body = isAccessFailure(failed) ? null : (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-person-correction="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          تعذّر تحميل النموذج
        </p>
        <Button
          variant="outline"
          className="h-10 gap-2 px-4"
          onClick={() => {
            void types.refetch();
            void membersQuery.refetch();
          }}
        >
          <RotateCw className="size-4" aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else if (types.data && !available) {
    body = <Message field="unavailable" title="طلبات تصحيح بيانات الأفراد غير متاحة حاليًا" text="يمكنك متابعة طلبات أسرتك السابقة من صفحة «طلباتي»." />;
  } else if (types.data && membersQuery.data) {
    const members = membersQuery.data.members.filter((m) => m.available && m.full_name);
    body = <CorrectionForm members={members} />;
  } else {
    body = (
      <div className="flex flex-col gap-3" data-person-correction="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-14 w-full rounded-2xl" />
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title={TITLE} description="صحّح اسم أحد أفراد أسرتك أو تاريخ ميلاده أو جنسه أو حالته الاجتماعية كما هي في الوثائق الرسمية." />
      {body}
    </div>
  );
}
