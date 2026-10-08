"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Controller, useForm, useWatch } from "react-hook-form";
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
import { useSubmitFamilyChangeRequest, useFamilyChangeRequestTypesQuery } from "@/lib/api/family-change-requests";
import { type FamilyProfile, isAccessFailure, useFamilyProfileQuery } from "@/lib/api/family-household";
import {
  RESIDENCE_UPDATE_FIELDS,
  type ResidenceUpdateValues,
  residenceUpdateChanges,
  residenceUpdateFormValues,
  residenceUpdateSchemaFor,
  toResidenceUpdateData,
} from "@/lib/schemas/family-residence-update";

type Residence = NonNullable<FamilyProfile["residence"]>;

const BACK = { href: "/family/requests/new", label: "طلب جديد" };
const TITLE = "تحديث بيانات السكن";
const REVIEW_NOTICE = "سيُرسل طلبك للمراجعة، ولن تتغير بيانات السجل الرسمي إلا بعد اعتماد الطلب وتطبيقه.";

function Message({ title, text, field, tone = "info" }: { title: string; text: string; field: string; tone?: "info" | "error" }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-residence-update={field}>
      {tone === "error" ? <AlertCircle className="size-6 text-danger" aria-hidden /> : <FileClock className="size-6 text-muted-foreground" aria-hidden />}
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
      return "لدى أسرتك طلب تحديث سكن مفتوح بالفعل. تابعه من «طلباتي» أو ألغِه قبل تقديم طلب جديد.";
    case "CHANGE_REQUEST_PRECONDITION_FAILED":
      return "لا يوجد سكن حالي مسجّل لأسرتك يمكن تصحيحه. تواصل مع فريق السجل.";
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

function TextField({
  id,
  label,
  error,
  placeholder,
  ...input
}: { id: string; label: string; error?: string; placeholder?: string } & React.ComponentProps<typeof Input>) {
  return (
    <div className="flex flex-col gap-1.5">
      <FieldLabel htmlFor={id} optional>
        {label}
      </FieldLabel>
      <Input id={id} className="h-11 rounded-xl" placeholder={placeholder} aria-invalid={Boolean(error)} {...input} />
      <FieldError message={error} />
    </div>
  );
}

function ResidenceForm({ residence }: { residence: Residence }) {
  const router = useRouter();
  const submitMutation = useSubmitFamilyChangeRequest();
  const statusKnown = residence.displacement_status !== null;
  const form = useForm<ResidenceUpdateValues>({
    resolver: zodResolver(residenceUpdateSchemaFor(residence)),
    defaultValues: residenceUpdateFormValues(residence),
  });
  const { register, control, setValue, setError, formState } = form;
  const errors = formState.errors;
  const displaced = useWatch({ control, name: "displacementStatus" }) === "DISPLACED";
  const [submitError, setSubmitError] = useState<string | null>(null);
  // One client_reference per proposal: a retry of the SAME proposal re-sends
  // it (the server replays, never duplicates); any change starts a new one.
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);

  function send(values: ResidenceUpdateValues) {
    if (sending.current) return;
    setSubmitError(null);
    if (!residenceUpdateChanges(residence, values)) {
      setSubmitError("لم تغيّر أي بيانات. عدّل الحقول التي تحتاج إلى تصحيح ثم أرسل الطلب.");
      return;
    }
    const data = toResidenceUpdateData(values);
    const reason = values.reason.trim() === "" ? null : values.reason.trim();
    const key = JSON.stringify([data, reason]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    submitMutation.mutate(
      { type: "RESIDENCE_UPDATE", client_reference: attempt.current.reference, reason, data },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => router.push(`/family/requests/${outcome.id}`),
        onError: (error) => {
          if (changeRequestErrorCode(error) === "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT") attempt.current = null;
          const fieldErrors = error instanceof ApiError && error.status === 422 ? error.validationErrors : undefined;
          for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
            const target = RESIDENCE_UPDATE_FIELDS[field];
            if (target && messages[0]) setError(target, { type: "server", message: messages[0] });
          }
          setSubmitError(fieldErrors?.data?.[0] ?? submitErrorMessage(error));
        },
      }
    );
  }

  const pending = submitMutation.isPending;

  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} className="flex flex-col gap-4" noValidate aria-busy={pending} data-residence-update="form">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800" data-residence-update-notice>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>{REVIEW_NOTICE}</span>
      </p>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">عنوان السكن الحالي</legend>
        <TextField id="residence-governorate" label="المحافظة" error={errors.governorate?.message} {...register("governorate")} />
        <TextField id="residence-city" label="المدينة" error={errors.city?.message} {...register("city")} />
        <TextField id="residence-area" label="المنطقة" error={errors.area?.message} {...register("area")} />
        <TextField id="residence-neighborhood" label="الحي" error={errors.neighborhood?.message} {...register("neighborhood")} />
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="residence-address-text" optional>
            العنوان التفصيلي
          </FieldLabel>
          <Textarea id="residence-address-text" rows={3} className="rounded-xl" aria-invalid={Boolean(errors.addressText)} {...register("addressText")} />
          <FieldError message={errors.addressText?.message} />
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">النزوح</legend>
        <TextField
          id="residence-original"
          label="السكن الأصلي قبل النزوح"
          placeholder="مثال: بني سهيلا – خانيونس"
          error={errors.originalResidenceText?.message}
          {...register("originalResidenceText")}
        />
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="residence-displacement-status" optional={!statusKnown}>
            هل الأسرة نازحة حاليًا؟
            {statusKnown && (
              <span className="text-danger" aria-hidden>
                *
              </span>
            )}
          </FieldLabel>
          <Controller
            control={control}
            name="displacementStatus"
            render={({ field }) => (
              <Select
                value={field.value === "" ? undefined : field.value}
                onValueChange={(value) => {
                  field.onChange(value);
                  // Not displaced → no displacement location (as the registry does).
                  if (value !== "DISPLACED") setValue("displacementLocationText", "");
                }}
                disabled={pending}
              >
                <SelectTrigger
                  id="residence-displacement-status"
                  className="h-11! w-full rounded-xl"
                  aria-required={statusKnown}
                  aria-invalid={Boolean(errors.displacementStatus)}
                >
                  <SelectValue placeholder="غير محدد" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="DISPLACED">نعم، نازحة</SelectItem>
                  <SelectItem value="NOT_DISPLACED">لا، غير نازحة</SelectItem>
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.displacementStatus?.message} />
        </div>
        {displaced && (
          <TextField
            id="residence-displacement-location"
            label="مكان النزوح الحالي"
            placeholder="مثال: مواصي خانيونس"
            error={errors.displacementLocationText?.message}
            {...register("displacementLocationText")}
          />
        )}
      </fieldset>

      <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
        <FieldLabel htmlFor="residence-reason" optional>
          سبب الطلب
        </FieldLabel>
        <Textarea id="residence-reason" rows={3} className="rounded-xl" disabled={pending} aria-invalid={Boolean(errors.reason)} {...register("reason")} />
        <FieldError message={errors.reason?.message} />
      </div>

      {submitError && (
        <Alert variant="destructive" data-residence-update-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُرسل الطلب</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending} data-residence-update-submit>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {pending ? "جارٍ الإرسال…" : "إرسال الطلب للمراجعة"}
      </Button>
    </form>
  );
}

/**
 * «تحديث بيانات السكن» (PWA-6.1): the household head proposes a correction of
 * the Family's CURRENT residence, prefilled from the registry. Offered only
 * while the server lists RESIDENCE_UPDATE (switch on, registered, permitted);
 * the server re-checks everything on submission.
 */
export function FamilyResidenceUpdateForm() {
  const types = useFamilyChangeRequestTypesQuery();
  const profile = useFamilyProfileQuery();

  let body: React.ReactNode;
  const failed = types.isError ? types.error : profile.isError ? profile.error : null;
  if (failed) {
    body = isAccessFailure(failed) ? null : (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-residence-update="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          تعذّر تحميل بيانات السكن
        </p>
        <Button
          variant="outline"
          className="h-10 gap-2 px-4"
          onClick={() => {
            void types.refetch();
            void profile.refetch();
          }}
        >
          <RotateCw className="size-4" aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else if (types.data && profile.data) {
    const available = types.data.meta.submission_enabled && types.data.data.some(({ type }) => type === "RESIDENCE_UPDATE");
    if (!available) {
      body = (
        <Message
          field="unavailable"
          title="طلبات تحديث السكن غير متاحة حاليًا"
          text="يمكنك متابعة طلبات أسرتك السابقة من صفحة «طلباتي»."
        />
      );
    } else if (profile.data.residence === null) {
      body = <Message field="no-residence" tone="error" title="لا يوجد سكن حالي مسجّل" text="لا يوجد سكن حالي لأسرتك يمكن تصحيحه. تواصل مع فريق السجل." />;
    } else {
      body = <ResidenceForm residence={profile.data.residence} />;
    }
  } else {
    body = (
      <div className="flex flex-col gap-3" data-residence-update="loading">
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
      <FamilyRequestsHeader back={BACK} title={TITLE} description="صحّح عنوان سكن أسرتك الحالي أو بيانات النزوح كما هي في الواقع." />
      {body}
    </div>
  );
}
