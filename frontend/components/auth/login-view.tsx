"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Loader2, LockKeyhole, Mail, ShieldCheck } from "lucide-react";
import { BrandLogo } from "@/components/shared/brand-logo";
import { SiteFooter } from "@/components/shared/site-footer";
import { useQueryClient } from "@tanstack/react-query";
// FB-UI-02: the Family Portal's authentication field patterns, reused as is.
import { FieldError, FormAlert, fieldClass } from "@/components/family/auth/auth-parts";
import { PasswordField } from "@/components/family/auth/password-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api/client";
import { ME_QUERY_KEY, login, useMeQuery } from "@/lib/api/auth";
import { safeNext } from "@/lib/navigation";
import { cn } from "@/lib/utils";

const loginSchema = z.object({
  email: z.string().trim().min(1, "البريد الإلكتروني مطلوب").email("صيغة البريد الإلكتروني غير صحيحة"),
  password: z.string().min(1, "كلمة المرور مطلوبة"),
});
type LoginValues = z.infer<typeof loginSchema>;

/** The server's generic failure (never reveals whether an account exists). */
function loginErrorMessage(error: unknown): string {
  if (error instanceof ApiError && (error.status === 422 || error.status === 429)) {
    const payload = error.payload as { message?: string; errors?: Record<string, string[]> } | null;
    return payload?.errors?.email?.[0] ?? payload?.message ?? "تعذّر تسجيل الدخول.";
  }
  return "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.";
}

/**
 * Staff login (docs/06 §59c): Sanctum session — the CSRF cookie is
 * initialised by the API client, the session cookie is HttpOnly, and no
 * token reaches JavaScript. Credentials are never put in the URL.
 *
 * FB-UI-02: the fields, errors and password control are the Family
 * Portal's (auth-parts, PasswordField); the heading, description and
 * ShieldCheck keep the Staff entrance distinguishable.
 */
export function LoginView() {
  const router = useRouter();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const me = useMeQuery();
  const next = safeNext(search.get("next"));
  const [error, setError] = useState<string | null>(null);
  // Presentation only: reveals the typed password locally; nothing else changes.
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginValues>({ resolver: zodResolver(loginSchema), defaultValues: { email: "", password: "" } });

  // Already signed in: go straight to the portal.
  useEffect(() => {
    if (me.data) router.replace(next);
  }, [me.data, next, router]);

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      const user = await login(values.email, values.password);
      // Nothing cached for a previous session survives into this one.
      queryClient.clear();
      queryClient.setQueryData(ME_QUERY_KEY, user);
      router.replace(next);
    } catch (e) {
      setError(loginErrorMessage(e));
    }
  });

  return (
    <main className="flex min-h-svh flex-col items-center justify-center bg-canvas px-4 py-8">
      <div className="flex w-full max-w-[400px] flex-col">
        {/* The official logo alone (FB-UI-02: no separate portal label). */}
        <div className="mb-5 flex flex-col items-center" data-brand-lockup>
          <BrandLogo className="h-12" />
        </div>

        <section className="rounded-widget border border-stroke-subtle bg-surface-1 p-6 shadow-e2 sm:p-7" aria-labelledby="login-title">
          <div className="mb-5 flex flex-col gap-1.5">
            <h1 id="login-title" className="flex items-center gap-2 text-[22px] leading-snug font-bold text-foreground">
              <ShieldCheck className="size-[22px] shrink-0 text-brand-700" aria-hidden data-staff-heading-icon />
              دخول الطاقم
            </h1>
            <p className="text-[15px] leading-relaxed text-muted-foreground">الوصول الإداري إلى نظام فامبوك</p>
          </div>

          {/* The Family authentication geometry (its --radius) for the fields, alert, toggle and button;
              the Staff card frame keeps its own. */}
          <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4 [--radius:0.75rem]" method="post" aria-busy={isSubmitting} data-auth-geometry="family">
            <FormAlert message={error} />

            <div className="flex flex-col gap-2">
              <Label htmlFor="login-email" className="text-sm font-medium">
                البريد الإلكتروني
              </Label>
              {/* The value is LTR; the icon sits on the right (physical sides, as in the Family Portal). */}
              <div className="relative">
                <Input
                  id="login-email"
                  type="email"
                  inputMode="email"
                  dir="ltr"
                  autoComplete="username"
                  autoCapitalize="none"
                  spellCheck={false}
                  autoFocus
                  className={cn(fieldClass, "pr-11 text-start")}
                  aria-invalid={errors.email ? true : undefined}
                  aria-describedby={errors.email ? "login-email-error" : undefined}
                  {...register("email")}
                />
                <Mail
                  className="pointer-events-none absolute right-3.5 top-1/2 size-[18px] -translate-y-1/2 text-muted-foreground"
                  aria-hidden
                  data-field-icon="email"
                />
              </div>
              <FieldError id="login-email-error" message={errors.email?.message} />
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="login-password" className="text-sm font-medium">
                كلمة المرور
              </Label>
              <PasswordField
                id="login-password"
                autoComplete="current-password"
                dir="ltr"
                withLockIcon
                // One show/hide control only: no browser-native reveal button (as the Family scope does in globals.css).
                className="[&::-ms-reveal]:hidden [&::-ms-clear]:hidden"
                visible={showPassword}
                onToggle={() => setShowPassword((v) => !v)}
                aria-invalid={errors.password ? true : undefined}
                aria-describedby={errors.password ? "login-password-error" : undefined}
                {...register("password")}
              />
              <FieldError id="login-password-error" message={errors.password?.message} />
            </div>

            {/* The Family field and button sizes; the default primary hover (the Family-only hover token is not defined here). */}
            <Button type="submit" disabled={isSubmitting} className="mt-1 h-12 w-full rounded-xl text-[15px] font-semibold">
              {isSubmitting ? (
                <>
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                  جارٍ تسجيل الدخول…
                </>
              ) : (
                "تسجيل الدخول"
              )}
            </Button>
          </form>
        </section>

        <p className="mt-3 flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground" data-staff-note>
          <LockKeyhole className="size-3.5 shrink-0 text-brand-700/70" aria-hidden />
          الدخول مخصص للطاقم المصرّح لهم فقط.
        </p>

        <SiteFooter className="mt-8" />
      </div>
    </main>
  );
}
