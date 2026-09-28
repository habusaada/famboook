"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertCircle, Eye, EyeOff, Loader2, LockKeyhole, NotebookText } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api/client";
import { ME_QUERY_KEY, login, useMeQuery } from "@/lib/api/auth";
import { safeNext } from "@/lib/navigation";

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

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null;
  return (
    <p id={id} className="flex items-center gap-1 text-xs text-danger" role="alert">
      <AlertCircle className="size-3.5 shrink-0" aria-hidden />
      {message}
    </p>
  );
}

/**
 * Staff login (docs/06 §59c): Sanctum session — the CSRF cookie is
 * initialised by the API client, the session cookie is HttpOnly, and no
 * token reaches JavaScript. Credentials are never put in the URL.
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
        {/* Brand identity, as in the Staff Portal sidebar. */}
        <div className="mb-5 flex items-center justify-center gap-3" data-brand-lockup>
          <span className="flex size-11 items-center justify-center rounded-lg bg-brand-700 text-white shadow-e1" aria-hidden>
            <NotebookText className="size-[22px]" strokeWidth={2} />
          </span>
          <span className="flex flex-col gap-0.5 leading-none">
            <span className="text-xl font-bold tracking-tight text-brand-900">Famboook</span>
            <span className="text-[13px] font-medium text-brand-700">بوابة الطاقم</span>
          </span>
        </div>

        <section className="rounded-widget border border-stroke-subtle bg-surface-1 p-6 shadow-e2 sm:p-7" aria-labelledby="login-title">
          <div className="mb-4 flex flex-col gap-0.5">
            <h1 id="login-title" className="text-xl leading-tight font-bold text-foreground">
              تسجيل الدخول
            </h1>
            <p className="text-sm text-muted-foreground">سجّل الدخول بحساب الطاقم للمتابعة.</p>
          </div>

          <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" method="post" aria-busy={isSubmitting}>
            {error && (
              <Alert variant="destructive" data-login-error>
                <AlertCircle className="size-4" />
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="login-email" className="text-[13px] font-medium">
                البريد الإلكتروني
              </Label>
              <Input
                id="login-email"
                type="email"
                dir="ltr"
                autoComplete="username"
                autoFocus
                className="h-11 bg-surface-2 text-[15px] focus-visible:bg-surface-1"
                aria-invalid={errors.email ? true : undefined}
                aria-describedby={errors.email ? "login-email-error" : undefined}
                {...register("email")}
              />
              <FieldError id="login-email-error" message={errors.email?.message} />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="login-password" className="text-[13px] font-medium">
                كلمة المرور
              </Label>
              <div className="relative">
                <Input
                  id="login-password"
                  type={showPassword ? "text" : "password"}
                  dir="ltr"
                  autoComplete="current-password"
                  className="h-11 bg-surface-2 ps-11 text-[15px] focus-visible:bg-surface-1"
                  aria-invalid={errors.password ? true : undefined}
                  aria-describedby={errors.password ? "login-password-error" : undefined}
                  {...register("password")}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
                  aria-pressed={showPassword}
                  aria-controls="login-password"
                  className="absolute start-1.5 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-control text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                  data-password-toggle
                >
                  {showPassword ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
                </button>
              </div>
              <FieldError id="login-password-error" message={errors.password?.message} />
            </div>

            <Button type="submit" disabled={isSubmitting} className="mt-1 h-11 text-[15px]">
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
      </div>
    </main>
  );
}
