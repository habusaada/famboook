"use client";

import { useState } from "react";
import Link from "next/link";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, CheckCircle2, FileClock, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { FieldError, FormAlert, primaryButtonClass } from "@/components/family/auth/auth-parts";
import { PasswordField } from "@/components/family/auth/password-field";
import { FAMILY_ME_QUERY_KEY, changeFamilyPassword, readFamilyAuthError, usePasswordChangeAvailability } from "@/lib/api/family-auth";
import { FAMILY_ACCOUNT_QUERY_KEY } from "@/lib/api/family-account";
import { type ChangePasswordValues, FAMILY_PASSWORD_MIN_LENGTH, changePasswordSchema } from "@/lib/schemas/family-auth";

const BLANK: ChangePasswordValues = { current_password: "", password: "", password_confirmation: "" };
const FIELDS = ["current_password", "password", "password_confirmation"] as const;

function ChangePasswordForm() {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  // Presentation only: reveals the typed passwords locally.
  const [visible, setVisible] = useState(false);
  const {
    register,
    handleSubmit,
    reset,
    setError: setFieldError,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordValues>({ resolver: zodResolver(changePasswordSchema), defaultValues: BLANK });

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    setDone(false);
    try {
      const user = await changeFamilyPassword(values.current_password, values.password, values.password_confirmation);
      // The server ended every session and signed this browser in again.
      queryClient.setQueryData(FAMILY_ME_QUERY_KEY, user);
      void queryClient.invalidateQueries({ queryKey: FAMILY_ACCOUNT_QUERY_KEY });
      reset(BLANK);
      setVisible(false);
      setDone(true);
    } catch (e) {
      const failure = readFamilyAuthError(e);
      let mapped = false;
      for (const field of FIELDS) {
        const message = failure.fields[field]?.[0];
        if (message) {
          setFieldError(field, { message });
          mapped = true;
        }
      }
      if (!mapped) setError(failure.message);
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" aria-busy={isSubmitting} data-change-password>
      {done && (
        <p role="status" className="flex items-start gap-2.5 rounded-xl bg-success-soft px-3.5 py-3 text-sm text-success" data-change-password-done>
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>تم تغيير كلمة المرور. أُنهيت جلساتك على الأجهزة الأخرى، وستبقى مسجّل الدخول على هذا الجهاز.</span>
        </p>
      )}
      <FormAlert message={error} />

      <div className="flex flex-col gap-2">
        <Label htmlFor="change-current-password" className="text-sm font-medium">
          كلمة المرور الحالية
        </Label>
        <PasswordField
          id="change-current-password"
          autoComplete="current-password"
          visible={visible}
          onToggle={() => setVisible((v) => !v)}
          aria-invalid={errors.current_password ? true : undefined}
          aria-describedby={errors.current_password ? "change-current-password-error" : undefined}
          {...register("current_password")}
        />
        <FieldError id="change-current-password-error" message={errors.current_password?.message} />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="change-new-password" className="text-sm font-medium">
          كلمة المرور الجديدة
        </Label>
        <PasswordField
          id="change-new-password"
          autoComplete="new-password"
          visible={visible}
          aria-invalid={errors.password ? true : undefined}
          aria-describedby={errors.password ? "change-new-password-error" : "change-new-password-hint"}
          {...register("password")}
        />
        <p id="change-new-password-hint" className="text-xs text-muted-foreground">
          لا تقل عن {FAMILY_PASSWORD_MIN_LENGTH} أحرف. يمكنك استخدام عبارة سهلة التذكّر.
        </p>
        <FieldError id="change-new-password-error" message={errors.password?.message} />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="change-password-confirmation" className="text-sm font-medium">
          تأكيد كلمة المرور الجديدة
        </Label>
        <PasswordField
          id="change-password-confirmation"
          autoComplete="new-password"
          visible={visible}
          aria-invalid={errors.password_confirmation ? true : undefined}
          aria-describedby={errors.password_confirmation ? "change-password-confirmation-error" : undefined}
          {...register("password_confirmation")}
        />
        <FieldError id="change-password-confirmation-error" message={errors.password_confirmation?.message} />
      </div>

      <Button type="submit" disabled={isSubmitting} className={primaryButtonClass}>
        {isSubmitting ? (
          <>
            <Loader2 className="size-4 animate-spin" aria-hidden />
            جارٍ تغيير كلمة المرور…
          </>
        ) : (
          "تغيير كلمة المرور"
        )}
      </Button>
    </form>
  );
}

/**
 * «تغيير كلمة المرور» (FU-16, docs/11 FP-ADR-078): the signed-in household
 * head changes their own password — current password, new password,
 * confirmation. Offered only while the server reports the surface open
 * (fail closed). The server ends every session and keeps this browser
 * signed in on a new one; no SMS. Passwords stay in the form's memory and
 * the request body only — never in browser storage or the query cache.
 */
export function ChangePassword() {
  const availability = usePasswordChangeAvailability();

  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          href="/family/account"
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          data-change-password-back
        >
          <ArrowRight className="size-4" aria-hidden />
          حسابي
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">تغيير كلمة المرور</h1>
      </header>

      {availability === "available" ? (
        <ChangePasswordForm />
      ) : availability === "loading" ? (
        <div data-change-password="loading">
          <p role="status" className="sr-only">
            جارٍ التحميل
          </p>
          <Skeleton className="h-72 w-full rounded-2xl" />
        </div>
      ) : (
        <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-change-password="unavailable">
          <FileClock className="size-6 text-muted-foreground" aria-hidden />
          <h2 className="text-base font-semibold text-foreground">تغيير كلمة المرور غير متاح حاليًا</h2>
          <Button asChild variant="outline" className="h-10 rounded-xl">
            <Link href="/family/account">العودة إلى حسابي</Link>
          </Button>
        </section>
      )}
    </div>
  );
}
