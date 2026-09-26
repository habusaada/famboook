"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertCircle, Loader2, NotebookText } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
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
    <div className="flex min-h-svh items-center justify-center bg-muted/40 p-4">
      <Card className="w-full max-w-sm">
        <CardHeader className="items-center text-center">
          <span className="mx-auto mb-1 flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <NotebookText className="size-5" />
          </span>
          <CardTitle className="text-xl">تسجيل الدخول</CardTitle>
          <CardDescription>بوابة الطاقم — Famboook</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" method="post">
            {error && (
              <Alert variant="destructive" data-login-error>
                <AlertCircle className="size-4" />
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="login-email">البريد الإلكتروني</Label>
              <Input id="login-email" type="email" dir="ltr" autoComplete="username" autoFocus {...register("email")} />
              {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="login-password">كلمة المرور</Label>
              <Input id="login-password" type="password" dir="ltr" autoComplete="current-password" {...register("password")} />
              {errors.password && <p className="text-xs text-destructive">{errors.password.message}</p>}
            </div>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              تسجيل الدخول
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
