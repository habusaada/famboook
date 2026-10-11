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
import {
  type FamilyRelationshipOption,
  useFamilyChangeRequestTypesQuery,
  useFamilyRelationshipTypesQuery,
  useSubmitFamilyChangeRequest,
} from "@/lib/api/family-change-requests";
import { isAccessFailure } from "@/lib/api/family-household";
import {
  ADD_MEMBER_DEFAULTS,
  ADD_MEMBER_FIELDS,
  type AddMemberInput,
  type AddMemberValues,
  addMemberSchema,
  toAddMemberData,
} from "@/lib/schemas/family-add-member";
import { genderLabels } from "@/lib/utils/family-portal-labels";
import { MARITAL_STATUSES, maritalStatusLabels } from "@/lib/utils/marital-status";

const BACK = { href: "/family/requests/new", label: "طلب جديد" };
const TITLE = "إضافة فرد إلى الأسرة";
const REVIEW_NOTICE = "سيُرسل طلبك للمراجعة، ولن تتغير بيانات السجل الرسمي إلا بعد اعتماد الطلب وتطبيقه.";

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-add-member={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/requests">الانتقال إلى طلباتي</Link>
      </Button>
    </section>
  );
}

/**
 * A safe Arabic message for a refused submission — never a raw error, and
 * never anything that says whether the National ID is known to the registry.
 */
function submitErrorMessage(error: unknown): string {
  switch (changeRequestErrorCode(error)) {
    case "CHANGE_REQUEST_ALREADY_OPEN":
      return "لدى أسرتك طلب مفتوح لإضافة فرد بنفس رقم الهوية. تابعه من «طلباتي».";
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

function AddMemberForm({ relationships }: { relationships: FamilyRelationshipOption[] }) {
  const router = useRouter();
  const submitMutation = useSubmitFamilyChangeRequest();
  const form = useForm<AddMemberInput, unknown, AddMemberValues>({
    resolver: zodResolver(addMemberSchema),
    defaultValues: ADD_MEMBER_DEFAULTS,
  });
  const { register, control, setError, formState } = form;
  const errors = formState.errors;
  const [submitError, setSubmitError] = useState<string | null>(null);
  // One client_reference per proposal: the same proposal re-sent after a
  // network failure replays on the server; any change starts a new one.
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);

  function send(values: AddMemberValues) {
    if (sending.current) return;
    setSubmitError(null);
    const data = toAddMemberData(values);
    const reason = values.reason.trim() === "" ? null : values.reason.trim();
    const key = JSON.stringify([data, reason]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    submitMutation.mutate(
      { type: "ADD_FAMILY_MEMBER", client_reference: attempt.current.reference, reason, data },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => router.push(`/family/requests/${outcome.id}`),
        onError: (error) => {
          if (changeRequestErrorCode(error) === "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT") attempt.current = null;
          const fieldErrors = error instanceof ApiError && error.status === 422 ? error.validationErrors : undefined;
          for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
            const target = ADD_MEMBER_FIELDS[field];
            if (target && messages[0]) setError(target, { type: "server", message: messages[0] });
          }
          setSubmitError(submitErrorMessage(error));
        },
      }
    );
  }

  const pending = submitMutation.isPending;

  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} className="flex flex-col gap-4" noValidate aria-busy={pending} data-add-member="form">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800" data-add-member-notice>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>
          {REVIEW_NOTICE} يتحقق فريق السجل من هوية الفرد وصلة القرابة قبل الاعتماد. لتسجيل مولود جديد استخدم طلب «تسجيل مولود» عند إتاحته.
        </span>
      </p>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">بيانات الفرد</legend>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-full-name">
            الاسم الكامل <Required />
          </FieldLabel>
          <Input id="member-full-name" className="h-11 rounded-xl" autoComplete="off" aria-required aria-invalid={Boolean(errors.fullName)} {...register("fullName")} />
          <FieldError message={errors.fullName?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-national-id">
            رقم الهوية <Required />
          </FieldLabel>
          <Input
            id="member-national-id"
            className="h-11 rounded-xl"
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            maxLength={32}
            aria-required
            aria-describedby="member-national-id-hint"
            aria-invalid={Boolean(errors.nationalId)}
            {...register("nationalId")}
          />
          <p id="member-national-id-hint" className="text-xs text-muted-foreground">
            9 أرقام كما في بطاقة الهوية أو شهادة الميلاد. لا تُدخل رقمًا تقديريًا.
          </p>
          <FieldError message={errors.nationalId?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-gender">
            الجنس <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="gender"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="member-gender" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.gender)}>
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
          <FieldLabel htmlFor="member-relationship">
            صلة القرابة برب الأسرة <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="relationship"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="member-relationship" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.relationship)}>
                  <SelectValue placeholder="اختر" />
                </SelectTrigger>
                <SelectContent>
                  {relationships.map((r) => (
                    <SelectItem key={r.code} value={r.code}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.relationship?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-birth-date" optional>
            تاريخ الميلاد
          </FieldLabel>
          <Input id="member-birth-date" type="date" dir="ltr" className="h-11 rounded-xl" max={new Date().toISOString().slice(0, 10)} aria-invalid={Boolean(errors.birthDate)} {...register("birthDate")} />
          <FieldError message={errors.birthDate?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-marital-status" optional>
            الحالة الاجتماعية
          </FieldLabel>
          <Controller
            control={control}
            name="maritalStatus"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="member-marital-status" className="h-11! w-full rounded-xl" aria-invalid={Boolean(errors.maritalStatus)}>
                  <SelectValue placeholder="غير محدد" />
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

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="member-mobile" optional>
            رقم الجوال
          </FieldLabel>
          <Input id="member-mobile" className="h-11 rounded-xl" dir="ltr" inputMode="tel" autoComplete="off" placeholder="05XXXXXXXX" aria-invalid={Boolean(errors.mobile)} {...register("mobile")} />
          <FieldError message={errors.mobile?.message} />
        </div>
      </fieldset>

      <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
        <FieldLabel htmlFor="member-reason" optional>
          سبب الطلب
        </FieldLabel>
        <Textarea id="member-reason" rows={3} className="rounded-xl" disabled={pending} aria-invalid={Boolean(errors.reason)} {...register("reason")} />
        <FieldError message={errors.reason?.message} />
      </div>

      {submitError && (
        <Alert variant="destructive" data-add-member-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُرسل الطلب</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending} data-add-member-submit>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {pending ? "جارٍ الإرسال…" : "إرسال الطلب للمراجعة"}
      </Button>
    </form>
  );
}

/**
 * «إضافة فرد إلى الأسرة» (docs/11 FP-ADR-076): the household head proposes a
 * member for their OWN Family. Offered only while the server lists
 * ADD_FAMILY_MEMBER (registered, family-submittable, channel open); the
 * relationship options come from the registry. Nothing here ever says
 * whether the National ID is already known — Staff decide at review.
 */
export function FamilyAddMemberForm() {
  const types = useFamilyChangeRequestTypesQuery();
  const available = Boolean(types.data?.meta.submission_enabled && types.data.data.some(({ type }) => type === "ADD_FAMILY_MEMBER"));
  const relationships = useFamilyRelationshipTypesQuery(available);

  let body: React.ReactNode;
  const failed = types.isError ? types.error : relationships.isError ? relationships.error : null;
  if (failed) {
    body = isAccessFailure(failed) ? null : (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-add-member="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          تعذّر تحميل النموذج
        </p>
        <Button
          variant="outline"
          className="h-10 gap-2 px-4"
          onClick={() => {
            void types.refetch();
            if (available) void relationships.refetch();
          }}
        >
          <RotateCw className="size-4" aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else if (types.data && !available) {
    body = <Message field="unavailable" title="طلبات إضافة فرد غير متاحة حاليًا" text="يمكنك متابعة طلبات أسرتك السابقة من صفحة «طلباتي»." />;
  } else if (types.data && relationships.data) {
    body = <AddMemberForm relationships={relationships.data} />;
  } else {
    body = (
      <div className="flex flex-col gap-3" data-add-member="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-14 w-full rounded-2xl" />
        <Skeleton className="h-96 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title={TITLE} description="اطلب إضافة فرد يعيش مع أسرتك وغير مسجّل فيها." />
      {body}
    </div>
  );
}
