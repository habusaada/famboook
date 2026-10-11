"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, CheckCircle2, FileClock, Info, Loader2, RotateCw, Send, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { FieldError as SharedFieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { FieldError, FormAlert } from "@/components/family/auth/auth-parts";
import { OtpInput } from "@/components/family/auth/otp-input";
import { PasswordField } from "@/components/family/auth/password-field";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { changeRequestErrorCode } from "@/lib/api/change-requests";
import { readFamilyAuthError } from "@/lib/api/family-auth";
import { useFamilyChangeRequestTypesQuery } from "@/lib/api/family-change-requests";
import {
  type ContactUpdateResult,
  type ContactUpdateState,
  useCompleteContactUpdate,
  useResendContactUpdateCode,
  useStartContactUpdate,
} from "@/lib/api/family-contact-update";
import { type FamilyMember, isAccessFailure, useFamilyMembersQuery } from "@/lib/api/family-household";
import { OTP_LENGTH } from "@/lib/schemas/family-auth";
import {
  CONTACT_UPDATE_DEFAULTS,
  type ContactUpdateInput,
  type ContactUpdateValues,
  contactUpdateEligible,
  contactUpdateSchema,
  normalizeMobile,
} from "@/lib/schemas/family-contact-update";

const BACK = { href: "/family/requests/new", label: "طلب جديد" };
const TITLE = "تحديث رقم الجوال";
const card = "rounded-2xl border border-border bg-surface-1 p-4";

/** After these the request is closed (or gone): only a fresh start helps. */
const TERMINAL = ["CONTACT_UPDATE_FAILED", "CONTACT_UPDATE_EXPIRED", "OTP_LOCKED", "CHANGE_REQUEST_INVALID_TRANSITION", "CHANGE_REQUEST_NOT_FOUND"];

/** A safe Arabic message for a refusal — never a raw error. */
function errorMessage(error: unknown): string {
  const failure = readFamilyAuthError(error);
  if (failure.code) return failure.message;
  switch (changeRequestErrorCode(error)) {
    case "CHANGE_REQUEST_NOT_FOUND":
      return "تعذّر العثور على هذا الطلب أو الفرد ضمن أسرتك. ابدأ من جديد.";
    case "CHANGE_REQUEST_INVALID_TRANSITION":
      return "انتهى هذا الطلب. ابدأ طلبًا جديدًا.";
    case "CHANGE_REQUEST_SUBMISSION_DISABLED":
    case "CHANGE_REQUEST_TYPE_UNAVAILABLE":
      return "تحديث رقم الجوال غير متاح حاليًا.";
    case "CHANGE_REQUEST_ALREADY_OPEN":
      return "لدى أسرتك طلب مفتوح لهذا الفرد. تابعه من «طلباتي».";
  }
  return failure.message || "تحقق من البيانات المدخلة.";
}

function terminal(error: unknown): boolean {
  const code = readFamilyAuthError(error).code ?? changeRequestErrorCode(error);
  return code !== null && TERMINAL.includes(code);
}

function Unavailable({ title }: { title: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-contact-update="unavailable">
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/requests">الانتقال إلى طلباتي</Link>
      </Button>
    </section>
  );
}

// ------------------------------------------------------------------ step 1: the member and the number

function DetailsStep({ members, onStarted }: { members: FamilyMember[]; onStarted: (state: ContactUpdateState) => void }) {
  const start = useStartContactUpdate();
  const form = useForm<ContactUpdateInput, unknown, ContactUpdateValues>({ resolver: zodResolver(contactUpdateSchema), defaultValues: CONTACT_UPDATE_DEFAULTS });
  const { register, control, setError, setValue, formState } = form;
  const errors = formState.errors;
  const memberRef = useWatch({ control, name: "memberRef" });
  const member = members.find((m) => m.member_ref === memberRef) ?? null;
  const self = Boolean(member?.is_household_head);
  const [error, setFormError] = useState<string | null>(null);
  const [visible, setVisible] = useState(false);
  // One client_reference per proposal; a retry of the same proposal re-sends it.
  const attempt = useRef<{ key: string; reference: string } | null>(null);

  function send(values: ContactUpdateValues) {
    if (start.isPending) return;
    setFormError(null);
    const mobile = normalizeMobile(values.mobile);
    const key = JSON.stringify([values.memberRef, mobile]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };
    start.mutate(
      {
        member_ref: values.memberRef,
        mobile,
        ...(values.self ? { current_password: values.currentPassword } : {}),
        client_reference: attempt.current.reference,
      },
      {
        onSuccess: (state) => {
          setValue("currentPassword", "");
          onStarted(state);
        },
        onError: (e) => {
          const failure = readFamilyAuthError(e);
          const fieldMap: Record<string, "memberRef" | "mobile" | "currentPassword"> = { member_ref: "memberRef", mobile: "mobile", current_password: "currentPassword" };
          let mapped = false;
          for (const [field, messages] of Object.entries(failure.fields)) {
            const target = fieldMap[field];
            if (target && messages[0]) {
              setError(target, { type: "server", message: messages[0] });
              mapped = true;
            }
          }
          if (!mapped) setFormError(errorMessage(e));
        },
      }
    );
  }

  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} className="flex flex-col gap-4" noValidate aria-busy={start.isPending} data-contact-update="details">
      <div className={`${card} flex flex-col gap-1.5`}>
        <FieldLabel htmlFor="contact-member">الفرد</FieldLabel>
        <Controller
          control={control}
          name="memberRef"
          render={({ field }) => (
            <Select
              value={field.value === "" ? undefined : field.value}
              onValueChange={(value) => {
                setFormError(null);
                field.onChange(value);
                const chosen = members.find((m) => m.member_ref === value);
                setValue("self", Boolean(chosen?.is_household_head));
                setValue("currentPassword", "");
              }}
              disabled={start.isPending}
            >
              <SelectTrigger id="contact-member" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.memberRef)}>
                <SelectValue placeholder="اختر فردًا من أسرتك" />
              </SelectTrigger>
              <SelectContent>
                {members.map((m) => (
                  <SelectItem key={m.member_ref} value={m.member_ref}>
                    {m.full_name}
                    {m.is_household_head ? " (أنت)" : ""}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
        <SharedFieldError message={errors.memberRef?.message} />
      </div>

      {member && (
        <>
          <section className={card} aria-labelledby="contact-current" data-contact-update-current>
            <h2 id="contact-current" className="mb-1 text-base font-semibold text-foreground">
              الرقم المسجّل حاليًا
            </h2>
            <p className="text-sm text-foreground tabular-nums" dir="ltr">
              {member.mobile_masked ?? "غير مسجّل"}
            </p>
          </section>

          <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800" data-contact-update-notice>
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            {self ? (
              <span>
                لتغيير رقم جوالك: إن كان رقمك الحالي موثّقًا فسنرسل رمزًا إلى رقمك الحالي ورمزًا إلى الرقم الجديد، وينتقل التوثيق إلى الرقم الجديد. إن لم يكن موثّقًا فسيُحدَّث الرقم، لكنه لن يُستخدم لاستعادة كلمة المرور حتى يوثّقه فريق السجل.
              </span>
            ) : (
              <span>سنرسل رمز تحقق إلى الرقم الجديد. الرمز يثبت الوصول إلى الرقم فقط، ولا يمنح هذا الفرد أي حساب أو صلاحية.</span>
            )}
          </p>

          <div className={`${card} flex flex-col gap-4`}>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="contact-mobile">رقم الجوال الجديد</FieldLabel>
              <Input
                id="contact-mobile"
                dir="ltr"
                inputMode="tel"
                autoComplete="off"
                placeholder="05xxxxxxxx"
                className="h-11 rounded-xl"
                aria-required
                aria-invalid={Boolean(errors.mobile)}
                disabled={start.isPending}
                {...register("mobile")}
              />
              <SharedFieldError message={errors.mobile?.message} />
            </div>
            {self && (
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="contact-current-password" className="text-sm font-medium">
                  كلمة المرور الحالية
                </Label>
                <PasswordField
                  id="contact-current-password"
                  autoComplete="current-password"
                  visible={visible}
                  onToggle={() => setVisible((v) => !v)}
                  aria-invalid={errors.currentPassword ? true : undefined}
                  aria-describedby={errors.currentPassword ? "contact-current-password-error" : undefined}
                  disabled={start.isPending}
                  {...register("currentPassword")}
                />
                <FieldError id="contact-current-password-error" message={errors.currentPassword?.message} />
              </div>
            )}
          </div>
        </>
      )}

      <FormAlert message={error} />

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={start.isPending || !member} data-contact-update-send>
        {start.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {start.isPending ? "جارٍ الإرسال…" : "إرسال رمز التحقق"}
      </Button>
    </form>
  );
}

// ------------------------------------------------------------------ step 2: the code(s)

function CodesStep({ state, onDone, onRestart }: { state: ContactUpdateState; onDone: (result: ContactUpdateResult) => void; onRestart: () => void }) {
  const complete = useCompleteContactUpdate();
  const resend = useResendContactUpdateCode();
  const [current, setCurrent] = useState(state);
  const [code, setCode] = useState("");
  const [oldCode, setOldCode] = useState("");
  const [fieldErrors, setFieldErrors] = useState<{ code?: string; old_code?: string }>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [closed, setClosed] = useState(false);
  const transfer = current.verification.trust_transfer;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (complete.isPending || closed) return;
    setError(null);
    setNotice(null);
    const missing: { code?: string; old_code?: string } = {};
    if (code.length !== OTP_LENGTH) missing.code = "أدخل الرمز المكوّن من 6 أرقام.";
    if (transfer && oldCode.length !== OTP_LENGTH) missing.old_code = "أدخل الرمز المكوّن من 6 أرقام.";
    setFieldErrors(missing);
    if (missing.code || missing.old_code) return;

    complete.mutate(
      { id: current.id, code, ...(transfer ? { oldCode } : {}) },
      {
        onSuccess: (result) => {
          setCode("");
          setOldCode("");
          onDone(result);
        },
        onError: (e) => {
          const failure = readFamilyAuthError(e);
          setFieldErrors({ code: failure.fields.code?.[0], old_code: failure.fields.old_code?.[0] });
          if (!failure.fields.code && !failure.fields.old_code) setError(errorMessage(e));
          if (terminal(e)) setClosed(true);
        },
      }
    );
  }

  function again(target: "new" | "old") {
    if (resend.isPending) return;
    setError(null);
    setNotice(null);
    resend.mutate(
      { id: current.id, target },
      {
        onSuccess: (next) => {
          setCurrent(next);
          setNotice("أُرسل رمز جديد. الرمز السابق لم يعد صالحًا.");
        },
        onError: (e) => {
          setError(errorMessage(e));
          if (terminal(e)) setClosed(true);
        },
      }
    );
  }

  const pending = complete.isPending || resend.isPending;

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4" aria-busy={pending} data-contact-update="codes">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>
          رقم الطلب <span className="tabular-nums">{current.request_code}</span>. لن يتغيّر الرقم في السجل إلا بعد إدخال {transfer ? "الرمزين" : "الرمز"} بشكل صحيح.
        </span>
      </p>

      <div className={`${card} flex flex-col gap-2`}>
        <Label htmlFor="contact-code-new" className="text-sm font-medium">
          الرمز المرسل إلى الرقم الجديد{" "}
          <span dir="ltr" className="tabular-nums">
            {current.verification.new_mobile?.masked}
          </span>
        </Label>
        <OtpInput id="contact-code-new" value={code} onChange={setCode} disabled={pending || closed} invalid={Boolean(fieldErrors.code)} describedBy={fieldErrors.code ? "contact-code-new-error" : undefined} />
        <FieldError id="contact-code-new-error" message={fieldErrors.code} />
        <Button type="button" variant="ghost" className="self-start" onClick={() => again("new")} disabled={pending || closed || !current.verification.new_mobile?.can_resend} data-resend="new">
          <RotateCw className="size-4" aria-hidden />
          إعادة إرسال الرمز
        </Button>
      </div>

      {transfer && (
        <div className={`${card} flex flex-col gap-2`}>
          <Label htmlFor="contact-code-old" className="text-sm font-medium">
            الرمز المرسل إلى رقمك الحالي{" "}
            <span dir="ltr" className="tabular-nums">
              {current.verification.old_mobile?.masked}
            </span>
          </Label>
          <OtpInput id="contact-code-old" value={oldCode} onChange={setOldCode} disabled={pending || closed} invalid={Boolean(fieldErrors.old_code)} describedBy={fieldErrors.old_code ? "contact-code-old-error" : undefined} />
          <FieldError id="contact-code-old-error" message={fieldErrors.old_code} />
          <Button type="button" variant="ghost" className="self-start" onClick={() => again("old")} disabled={pending || closed || !current.verification.old_mobile?.can_resend} data-resend="old">
            <RotateCw className="size-4" aria-hidden />
            إعادة إرسال الرمز
          </Button>
        </div>
      )}

      <FormAlert message={notice} tone="info" />
      <FormAlert message={error} />

      {closed ? (
        <Button type="button" variant="outline" className="h-12 w-full rounded-xl text-base" onClick={onRestart} data-contact-update-restart>
          ابدأ من جديد
        </Button>
      ) : (
        <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending} data-contact-update-verify>
          {complete.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <CheckCircle2 className="size-4" aria-hidden />}
          {complete.isPending ? "جارٍ التحقق…" : "تأكيد وتحديث الرقم"}
        </Button>
      )}
    </form>
  );
}

// ------------------------------------------------------------------ step 3: done

function DoneStep({ result, self }: { result: ContactUpdateResult; self: boolean }) {
  return (
    <section className={`${card} flex flex-col gap-3`} role="status" data-contact-update="done">
      <p className="flex items-center gap-2 text-base font-semibold text-success">
        <CheckCircle2 className="size-5" aria-hidden />
        تم تحديث رقم الجوال.
      </p>
      {self && result.trust_transferred && <p className="text-sm text-foreground">انتقل توثيق رقمك إلى الرقم الجديد، وستصلك رموز التحقق واستعادة كلمة المرور عليه.</p>}
      {self && !result.trust_transferred && (
        <p className="flex items-start gap-2 rounded-xl bg-warning-soft px-3.5 py-3 text-sm text-warning" data-contact-update-recovery-warning>
          <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>رقمك الجديد غير موثّق بعد، لذلك لا يمكن استخدامه لاستعادة كلمة المرور. لتوثيقه، يُرجى مراجعة إدارة السجل.</span>
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" className="h-10 rounded-xl">
          <Link href={`/family/requests/${result.id}`}>عرض الطلب</Link>
        </Button>
        <Button asChild variant="ghost" className="h-10 rounded-xl">
          <Link href="/family/requests">طلباتي</Link>
        </Button>
      </div>
    </section>
  );
}

/**
 * «تحديث رقم الجوال» (CONTACT_UPDATE, docs/11 FP-ADR-079): the household
 * head picks a member (or themself), enters the new number — and, for their
 * own number, the current password — then the code(s). Offered only while
 * the server lists CONTACT_UPDATE. The server decides and applies; nothing
 * is kept in the browser.
 */
export function FamilyContactUpdateForm() {
  const types = useFamilyChangeRequestTypesQuery();
  const available = Boolean(types.data?.meta.submission_enabled && types.data.data.some(({ type }) => type === "CONTACT_UPDATE"));
  const membersQuery = useFamilyMembersQuery();
  const [started, setStarted] = useState<ContactUpdateState | null>(null);
  const [done, setDone] = useState<ContactUpdateResult | null>(null);
  // A fresh form after a restart.
  const [round, setRound] = useState(0);

  let body: React.ReactNode;
  const failed = types.isError ? types.error : membersQuery.isError ? membersQuery.error : null;
  if (failed) {
    body = isAccessFailure(failed) ? null : (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-contact-update="error">
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
    body = <Unavailable title="تحديث رقم الجوال غير متاح حاليًا" />;
  } else if (done && started) {
    body = <DoneStep result={done} self={started.verification.self} />;
  } else if (started) {
    body = (
      <CodesStep
        state={started}
        onDone={setDone}
        onRestart={() => {
          setStarted(null);
          setRound((r) => r + 1);
        }}
      />
    );
  } else if (types.data && membersQuery.data) {
    body = <DetailsStep key={round} members={membersQuery.data.members.filter(contactUpdateEligible)} onStarted={setStarted} />;
  } else {
    body = (
      <div className="flex flex-col gap-3" data-contact-update="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-14 w-full rounded-2xl" />
        <Skeleton className="h-56 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title={TITLE} description="حدّث رقم جوال أحد أفراد أسرتك أو رقمك، بعد التحقق برمز يُرسل إلى الرقم الجديد." />
      {body}
    </div>
  );
}
