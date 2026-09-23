"use client";

// Local-development-only helper. Establishes the Sanctum SPA session by
// calling the backend's local-only /dev-login route via a fetch() issued
// from this page (i.e. from the localhost:3000 top-level context), instead
// of a direct browser navigation to the backend host. Visiting the backend
// directly in a separate tab writes the session cookie into a different
// storage partition than the one the SPA's own credentialed fetches read
// from (observed in Firefox's Total Cookie Protection / state partitioning);
// triggering the request from inside the SPA keeps both in the same
// partition, matching how the real login flow will work once built.

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AlertCircle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Status = "pending" | "success" | "error";

export function DevLoginView() {
  const router = useRouter();
  const [status, setStatus] = useState<Status>("pending");
  const [detail, setDetail] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setStatus("pending");
      setDetail(null);

      try {
        const response = await fetch(`${API_URL}/dev-login`, {
          credentials: "include",
        });

        if (cancelled) return;

        if (!response.ok) {
          setStatus("error");
          setDetail(
            response.status === 404
              ? "المسار غير متاح. هذا المسار يعمل فقط في بيئة التطوير المحلية."
              : `فشل تسجيل الدخول التجريبي (رمز الحالة ${response.status}).`
          );
          return;
        }

        setStatus("success");
        router.replace("/families");
      } catch {
        if (!cancelled) {
          setStatus("error");
          setDetail("تعذّر الاتصال بالخادم على " + API_URL);
        }
      }
    }

    run();

    return () => {
      cancelled = true;
    };
  }, [attempt, router]);

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-8">
      <Card size="sm" className="w-full max-w-sm">
        <CardContent className="flex flex-col items-center gap-4 text-center">
          {status === "pending" && (
            <>
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                جارٍ إنشاء جلسة تطوير تجريبية...
              </p>
            </>
          )}

          {status === "success" && (
            <p className="text-sm text-muted-foreground">
              تم تسجيل الدخول، جارٍ التحويل...
            </p>
          )}

          {status === "error" && (
            <div className="flex w-full flex-col gap-3">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر إنشاء جلسة التطوير</AlertTitle>
                <AlertDescription>{detail}</AlertDescription>
              </Alert>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAttempt((n) => n + 1)}
              >
                إعادة المحاولة
              </Button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
