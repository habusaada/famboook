"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { FormAlert, StepHeading, primaryButtonClass } from "@/components/family/auth/auth-parts";
import { OtpInput } from "@/components/family/auth/otp-input";
import { type ChallengeTimers, readFamilyAuthError } from "@/lib/api/family-auth";
import { OTP_LENGTH } from "@/lib/schemas/family-auth";

type Props = {
  challenge: string;
  timers: ChallengeTimers;
  /** The endpoints of the workflow: activation or password reset. */
  verify: (challenge: string, code: string) => Promise<unknown>;
  resend: (challenge: string) => Promise<ChallengeTimers>;
  onVerified: () => void;
  onRestart: () => void;
};

/**
 * The code step, shared by activation and password reset — the same screen,
 * the same rules. Deliberately says nothing about the destination — not
 * even masked digits — and words the delivery conditionally: the same screen
 * is shown whether or not a code was really sent. The countdown runs on the
 * server's values; the server still enforces the cooldown.
 */
export function OtpStep({ challenge, timers, verify, resend, onVerified, onRestart }: Props) {
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);
  const [resending, setResending] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(timers.resend_after_seconds);
  const [canResend, setCanResend] = useState(timers.can_resend);
  // A locked or superseded code: only a fresh start helps.
  const [dead, setDead] = useState(false);

  const counting = secondsLeft > 0;
  useEffect(() => {
    if (!counting) return;
    const timer = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000);
    return () => clearInterval(timer);
  }, [counting]);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (code.length !== OTP_LENGTH || verifying || dead) return;
    setError(null);
    setInfo(null);
    setVerifying(true);
    try {
      await verify(challenge, code);
      onVerified();
    } catch (e) {
      const failure = readFamilyAuthError(e);
      setError(failure.message || failure.fields.code?.[0] || failure.fields.challenge?.[0] || null);
      setCode("");
      if (failure.code === "OTP_LOCKED") setDead(true);
      setVerifying(false);
    }
  }

  async function requestNewCode() {
    if (resending || counting || !canResend || dead) return;
    setError(null);
    setInfo(null);
    setResending(true);
    try {
      const next = await resend(challenge);
      setSecondsLeft(next.resend_after_seconds);
      setCanResend(next.can_resend);
      setCode("");
      setInfo("تم طلب رمز جديد. الرمز السابق لم يعد صالحًا.");
    } catch (e) {
      const failure = readFamilyAuthError(e);
      setError(failure.message);
      if (failure.code === "OTP_COOLDOWN" && failure.retryAfterSeconds !== null) setSecondsLeft(failure.retryAfterSeconds);
      if (failure.code === "OTP_SEND_LIMIT") setCanResend(false);
      if (failure.code === "OTP_LOCKED") setDead(true);
    } finally {
      setResending(false);
    }
  }

  return (
    <section aria-labelledby="family-otp-title">
      <StepHeading id="family-otp-title" title="أدخل رمز التحقق">
        إذا كانت البيانات مطابقة لسجلاتنا، أرسلنا رمز تحقق إلى رقم الجوال الموثّق المسجّل لدينا.
      </StepHeading>

      <form onSubmit={submit} noValidate className="flex flex-col gap-4" aria-busy={verifying}>
        <FormAlert message={error} />
        <FormAlert message={info} tone="info" />

        <div className="flex flex-col gap-2">
          <Label htmlFor="family-otp" className="text-sm font-medium">
            رمز التحقق المكوّن من {OTP_LENGTH} أرقام
          </Label>
          <OtpInput id="family-otp" value={code} onChange={setCode} disabled={verifying || dead} invalid={error !== null && !dead} />
        </div>

        <Button type="submit" disabled={code.length !== OTP_LENGTH || verifying || dead} className={primaryButtonClass}>
          {verifying ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              جارٍ التحقق…
            </>
          ) : (
            "تحقق"
          )}
        </Button>
      </form>

      <div className="mt-5 flex flex-col items-center gap-1 text-sm">
        {!dead && canResend && counting && (
          <p className="text-muted-foreground" aria-live="off" data-resend-countdown>
            إعادة الإرسال بعد <span dir="ltr" className="font-semibold tabular-nums text-foreground">{secondsLeft}</span> ثانية
          </p>
        )}
        {!dead && canResend && !counting && (
          <Button type="button" variant="link" className="h-10 px-2 text-sm font-semibold" disabled={resending} onClick={requestNewCode}>
            {resending ? "جارٍ طلب رمز جديد…" : "إعادة إرسال الرمز"}
          </Button>
        )}
        {!dead && !canResend && <p className="text-muted-foreground">لا يمكن طلب رمز آخر لهذه المحاولة.</p>}

        <Button type="button" variant={dead ? "outline" : "ghost"} className="h-10 rounded-xl px-4 text-sm text-muted-foreground" onClick={onRestart}>
          البدء من جديد
        </Button>
      </div>
    </section>
  );
}
