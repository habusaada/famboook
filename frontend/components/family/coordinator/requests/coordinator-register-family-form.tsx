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
import {
  type CoordinatorPlacementOption,
  coordinatorRequestErrorMessage,
  coordinatorSubmitErrorMessage,
  useCoordinatorPlacementsQuery,
  useCoordinatorRequestTypesQuery,
  useSubmitCoordinatorFamilyAdd,
} from "@/lib/api/coordinator-requests";
import {
  NO_BRANCH,
  REGISTER_FAMILY_DEFAULTS,
  REGISTER_FAMILY_FIELDS,
  type RegisterFamilyInput,
  type RegisterFamilyValues,
  registerFamilySchema,
  toPlacement,
  toRegisterFamilyData,
} from "@/lib/schemas/coordinator-register-family";
import { genderLabels } from "@/lib/utils/family-portal-labels";
import { MARITAL_STATUSES, maritalStatusLabels } from "@/lib/utils/marital-status";

const BACK = { href: "/family/coordinator/requests/new", label: "طلب جديد" };
const TITLE = "تسجيل أسرة جديدة";

function Required() {
  return (
    <span className="text-danger" aria-hidden>
      *
    </span>
  );
}

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-register-family={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/coordinator/requests">الانتقال إلى طلبات التنسيق</Link>
      </Button>
    </section>
  );
}

function branchLabel(branch: CoordinatorPlacementOption["branches"][number]): string {
  const name = branch.name ?? branch.code;
  return branch.group?.name ? `${name} — ${branch.group.name}` : name;
}

function RegisterFamilyForm({ placements }: { placements: CoordinatorPlacementOption[] }) {
  const router = useRouter();
  const submitMutation = useSubmitCoordinatorFamilyAdd();
  const single = placements.length === 1 ? placements[0] : null;
  const form = useForm<RegisterFamilyInput, unknown, RegisterFamilyValues>({
    resolver: zodResolver(registerFamilySchema),
    defaultValues: { ...REGISTER_FAMILY_DEFAULTS, clanCode: single?.clan.code ?? "" },
  });
  const { register, control, setError, setValue, formState } = form;
  const errors = formState.errors;
  const clanCode = useWatch({ control, name: "clanCode" });
  const clan = placements.find((p) => p.clan.code === clanCode) ?? null;
  const [submitError, setSubmitError] = useState<string | null>(null);
  // One client_reference per proposal; never re-sent automatically.
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);

  function send(values: RegisterFamilyValues) {
    if (sending.current) return;
    setSubmitError(null);
    const placement = toPlacement(values);
    const data = toRegisterFamilyData(values);
    const reason = values.reason.trim() === "" ? null : values.reason.trim();
    const key = JSON.stringify([placement, data, reason]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    submitMutation.mutate(
      { client_reference: attempt.current.reference, reason, placement, data },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => router.push(`/family/coordinator/requests/${outcome.id}`),
        onError: (error) => {
          if (changeRequestErrorCode(error) === "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT") attempt.current = null;
          const fieldErrors = error instanceof ApiError && error.status === 422 ? error.validationErrors : undefined;
          for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
            const target = REGISTER_FAMILY_FIELDS[field];
            if (target && messages[0]) setError(target, { type: "server", message: messages[0] });
          }
          setSubmitError(coordinatorSubmitErrorMessage(error));
        },
      }
    );
  }

  const pending = submitMutation.isPending;

  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} className="flex flex-col gap-4" noValidate aria-busy={pending} data-coordinator-register-family="form">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>لا تُنشأ الأسرة في السجل إلا بعد أن يراجع فريق السجل الطلب ويعتمده ثم يطبّقه. يُضاف بقية الأفراد لاحقًا بطلبات «إضافة فرد».</span>
      </p>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">مكان الأسرة</legend>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-family-clan">
            العائلة <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="clanCode"
            render={({ field }) => (
              <Select
                value={field.value === "" ? undefined : field.value}
                onValueChange={(value) => {
                  field.onChange(value);
                  setValue("branchCode", "");
                }}
                disabled={pending}
              >
                <SelectTrigger id="coordinator-family-clan" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.clanCode)}>
                  <SelectValue placeholder="اختر" />
                </SelectTrigger>
                <SelectContent>
                  {placements.map((p) => (
                    <SelectItem key={p.clan.code} value={p.clan.code}>
                      {p.clan.name ?? p.clan.code}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.clanCode?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-family-branch">
            الفرع <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="branchCode"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending || clan === null}>
                <SelectTrigger
                  id="coordinator-family-branch"
                  className="h-11! w-full rounded-xl"
                  aria-required
                  aria-invalid={Boolean(errors.branchCode)}
                  aria-describedby="coordinator-family-branch-hint"
                >
                  <SelectValue placeholder={clan ? "اختر" : "اختر العائلة أولًا"} />
                </SelectTrigger>
                <SelectContent>
                  {clan?.branchless_allowed && <SelectItem value={NO_BRANCH}>دون فرع (على مستوى العائلة)</SelectItem>}
                  {clan?.branches.map((b) => (
                    <SelectItem key={b.code} value={b.code}>
                      {branchLabel(b)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <p id="coordinator-family-branch-hint" className="text-xs text-muted-foreground">
            {clan && !clan.branchless_allowed ? "نطاقك في هذه العائلة يتطلب اختيار فرع." : "تظهر الفروع المتاحة ضمن نطاقك فقط."}
          </p>
          <FieldError message={errors.branchCode?.message} />
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">رب الأسرة</legend>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-head-full-name">
            الاسم الكامل <Required />
          </FieldLabel>
          <Input id="coordinator-head-full-name" className="h-11 rounded-xl" autoComplete="off" aria-required aria-invalid={Boolean(errors.fullName)} {...register("fullName")} />
          <FieldError message={errors.fullName?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-head-national-id">
            رقم الهوية <Required />
          </FieldLabel>
          <Input
            id="coordinator-head-national-id"
            className="h-11 rounded-xl"
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            maxLength={32}
            aria-required
            aria-describedby="coordinator-head-national-id-hint"
            aria-invalid={Boolean(errors.nationalId)}
            {...register("nationalId")}
          />
          <p id="coordinator-head-national-id-hint" className="text-xs text-muted-foreground">
            9 أرقام كما في بطاقة الهوية. لا تُدخل رقمًا تقديريًا.
          </p>
          <FieldError message={errors.nationalId?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-head-gender">
            الجنس <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="gender"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="coordinator-head-gender" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.gender)}>
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
          <FieldLabel htmlFor="coordinator-head-birth-date" optional>
            تاريخ الميلاد
          </FieldLabel>
          <Input id="coordinator-head-birth-date" type="date" dir="ltr" className="h-11 rounded-xl" max={new Date().toISOString().slice(0, 10)} aria-invalid={Boolean(errors.birthDate)} {...register("birthDate")} />
          <FieldError message={errors.birthDate?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-head-marital-status" optional>
            الحالة الاجتماعية
          </FieldLabel>
          <Controller
            control={control}
            name="maritalStatus"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="coordinator-head-marital-status" className="h-11! w-full rounded-xl" aria-invalid={Boolean(errors.maritalStatus)}>
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
          <FieldLabel htmlFor="coordinator-head-mobile" optional>
            رقم الجوال
          </FieldLabel>
          <Input id="coordinator-head-mobile" className="h-11 rounded-xl" dir="ltr" inputMode="tel" autoComplete="off" placeholder="05XXXXXXXX" aria-invalid={Boolean(errors.mobile)} {...register("mobile")} />
          <FieldError message={errors.mobile?.message} />
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">السكن الأصلي</legend>
        <FieldLabel htmlFor="coordinator-original-residence">
          مكان السكن الأصلي (قبل النزوح) <Required />
        </FieldLabel>
        <Input
          id="coordinator-original-residence"
          className="h-11 rounded-xl"
          autoComplete="off"
          maxLength={255}
          aria-required
          aria-describedby="coordinator-original-residence-hint"
          aria-invalid={Boolean(errors.originalResidence)}
          {...register("originalResidence")}
        />
        <p id="coordinator-original-residence-hint" className="text-xs text-muted-foreground">
          البلدة أو المدينة الأصلية فقط؛ لا يُطلب السكن الحالي في هذا الطلب.
        </p>
        <FieldError message={errors.originalResidence?.message} />
      </fieldset>

      <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
        <FieldLabel htmlFor="coordinator-family-reason" optional>
          سبب الطلب
        </FieldLabel>
        <Textarea id="coordinator-family-reason" rows={3} className="rounded-xl" disabled={pending} aria-invalid={Boolean(errors.reason)} {...register("reason")} />
        <FieldError message={errors.reason?.message} />
      </div>

      {submitError && (
        <Alert variant="destructive" data-coordinator-submit-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُرسل الطلب</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending} data-coordinator-submit>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {pending ? "جارٍ الإرسال…" : "إرسال الطلب للمراجعة"}
      </Button>
    </form>
  );
}

/**
 * «تسجيل أسرة جديدة» in Coordinator Space (CO-02.6b, FAMILY_ADD): offered
 * only while the server lists REGISTER_FAMILY for this Coordinator. The
 * placement choices are exactly the server's (GET …/requests/placements —
 * active structure in the CURRENT scope; «دون فرع» only where allowed); the
 * submission re-checks them. The head's identity and the ORIGINAL residence
 * only. Nothing here says whether the National ID is already known.
 */
export function CoordinatorRegisterFamilyForm() {
  const types = useCoordinatorRequestTypesQuery();
  const available = Boolean(types.data?.meta.submission_enabled && types.data.data.some(({ type }) => type === "REGISTER_FAMILY"));
  const placements = useCoordinatorPlacementsQuery(available);

  let body: React.ReactNode;
  const failed = types.isError ? types.error : placements.isError ? placements.error : null;
  if (failed) {
    body = (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-register-family="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          {coordinatorRequestErrorMessage(failed)}
        </p>
        <Button
          variant="outline"
          className="h-10 gap-2 px-4"
          onClick={() => {
            void types.refetch();
            if (available) void placements.refetch();
          }}
        >
          <RotateCw className="size-4" aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else if (types.data && !available) {
    body = <Message field="unavailable" title="طلبات تسجيل أسرة غير متاحة لك حاليًا" text="يمكنك متابعة طلباتك السابقة من «طلبات التنسيق»." />;
  } else if (placements.data && placements.data.length === 0) {
    body = <Message field="no-placement" title="لا يوجد مكان متاح لتسجيل أسرة ضمن نطاقك" text="تواصل مع إدارة السجل إذا كان نطاقك غير صحيح." />;
  } else if (types.data && placements.data) {
    body = <RegisterFamilyForm placements={placements.data} />;
  } else {
    body = (
      <div className="flex flex-col gap-3" data-coordinator-register-family="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-32 w-full rounded-2xl" />
        <Skeleton className="h-96 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title={TITLE} description="اقترح تسجيل أسرة غير مسجّلة في عائلة أو فرع ضمن نطاقك." />
      {body}
    </div>
  );
}
