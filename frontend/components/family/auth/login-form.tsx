"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { IdCard, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  FamilyAuthCard,
  FieldError,
  FormAlert,
  StepHeading,
  fieldClass,
  primaryButtonClass,
  textLinkClass,
} from "@/components/family/auth/auth-parts";
import { PasswordField } from "@/components/family/auth/password-field";
import { useFamilySignIn } from "@/components/family/auth/use-family-sign-in";
import { familyLogin, readFamilyAuthError } from "@/lib/api/family-auth";
import { type LoginInput, type LoginValues, loginSchema } from "@/lib/schemas/family-auth";
import { cn } from "@/lib/utils";
import { InstallFamboook } from "@/components/family/pwa/install-famboook";

/**
 * Family login (docs/11 §30a): National ID + password on the Sanctum
 * session — the CSRF cookie is initialised by the API client, the session
 * cookie is HttpOnly and no token reaches JavaScript.
 *
 * The server answers every credential, account and eligibility failure with
 * one code, and this form shows one sentence for it: nothing here can tell
 * whether a National ID has an account. The page does not ask who is signed
 * in; a successful login replaces whatever session the browser had.
 */
export function FamilyLoginForm() {
  const signIn = useFamilySignIn();
  const [error, setError] = useState<string | null>(null);
  const [signedIn, setSignedIn] = useState(false);
  // Presentation only: reveals the typed password locally.
  const [visible, setVisible] = useState(false);
  const {
    register,
    handleSubmit,
    setError: setFieldError,
    resetField,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput, unknown, LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { national_id: "", password: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      const user = await familyLogin(values.national_id, values.password);
      setSignedIn(true);
      signIn(user);
    } catch (e) {
      const failure = readFamilyAuthError(e);
      const idError = failure.fields.national_id?.[0];
      const passwordError = failure.fields.password?.[0];
      if (idError) setFieldError("national_id", { message: idError });
      if (passwordError) setFieldError("password", { message: passwordError });
      if (!idError && !passwordError) setError(failure.message);
      // A refused password is not kept in the field.
      if (failure.code === "INVALID_CREDENTIALS") resetField("password");
    }
  });

  const busy = isSubmitting || signedIn;

  return (
    <FamilyAuthCard
      step="LOGIN"
      below={
        <>
          <p>
            ليس لديك حساب؟{" "}
            <Link href="/family/activate" className={textLinkClass}>
              تفعيل الحساب
            </Link>
          </p>
          <InstallFamboook className="mt-2" />
        </>
      }
    >
      <section aria-labelledby="family-login-title">
        <StepHeading id="family-login-title" title="تسجيل الدخول">
          أدخل رقم الهوية وكلمة المرور للوصول إلى بوابة الأسرة.
        </StepHeading>

        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-busy={busy}>
          <FormAlert message={error} />

          <div className="flex flex-col gap-2">
            <Label htmlFor="family-login-national-id" className="text-sm font-medium">
              رقم الهوية
            </Label>
            {/* The value is LTR; the icon sits on the right (physical sides, see PasswordField). */}
            <div className="relative">
              <Input
                id="family-login-national-id"
                type="text"
                inputMode="numeric"
                dir="ltr"
                autoComplete="username"
                autoFocus
                className={cn(fieldClass, "pr-11 text-start tracking-wide")}
                aria-invalid={errors.national_id ? true : undefined}
                aria-describedby={errors.national_id ? "family-login-national-id-error" : undefined}
                {...register("national_id")}
              />
              <IdCard
                className="pointer-events-none absolute right-3.5 top-1/2 size-[18px] -translate-y-1/2 text-muted-foreground"
                aria-hidden
                data-field-icon="national-id"
              />
            </div>
            <FieldError id="family-login-national-id-error" message={errors.national_id?.message} />
          </div>

          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
              <Label htmlFor="family-login-password" className="text-sm font-medium">
                كلمة المرور
              </Label>
              <Link href="/family/forgot-password" className={cn(textLinkClass, "text-[13px]")}>
                نسيت كلمة المرور؟
              </Link>
            </div>
            <PasswordField
              id="family-login-password"
              autoComplete="current-password"
              // Passwords may be Arabic, English or mixed.
              dir="auto"
              withLockIcon
              visible={visible}
              onToggle={() => setVisible((v) => !v)}
              aria-invalid={errors.password ? true : undefined}
              aria-describedby={errors.password ? "family-login-password-error" : undefined}
              {...register("password")}
            />
            <FieldError id="family-login-password-error" message={errors.password?.message} />
          </div>

          <Button type="submit" disabled={busy} className={primaryButtonClass}>
            {/* Full-width, fixed-height button: the label swap cannot shift the layout. */}
            {busy ? (
              <span className="inline-flex items-center justify-center gap-2">
                <Loader2 className="size-4 shrink-0 animate-spin" aria-hidden />
                <span>جارٍ تسجيل الدخول...</span>
              </span>
            ) : (
              "تسجيل الدخول"
            )}
          </Button>
          {/* A disabled button may lose focus: announce the wait to assistive tech. */}
          <p role="status" className="sr-only">
            {busy ? "جارٍ تسجيل الدخول..." : ""}
          </p>
        </form>
      </section>
    </FamilyAuthCard>
  );
}
