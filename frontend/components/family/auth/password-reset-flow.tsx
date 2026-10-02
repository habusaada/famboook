"use client";

import { useState } from "react";
import Link from "next/link";
import { Loader2 } from "lucide-react";
import { FamilyAuthCard, textLinkClass } from "@/components/family/auth/auth-parts";
import { NationalIdStep } from "@/components/family/auth/national-id-step";
import { OtpStep } from "@/components/family/auth/otp-step";
import { PasswordStep } from "@/components/family/auth/password-step";
import { useFamilySignIn } from "@/components/family/auth/use-family-sign-in";
import {
  type ActivationStart,
  type ChallengeTimers,
  type FamilyUser,
  completePasswordReset,
  resendPasswordReset,
  startPasswordReset,
  verifyPasswordReset,
} from "@/lib/api/family-auth";

// The step is presentation; the SERVER decides what a challenge may do next.
type State =
  | { step: "IDENTIFIER"; notice: string | null }
  | { step: "OTP"; challenge: string; timers: ChallengeTimers }
  | { step: "NEW_PASSWORD"; challenge: string }
  | { step: "DONE" };

const STEP_NUMBER = { IDENTIFIER: 1, OTP: 2, NEW_PASSWORD: 3 } as const;

/**
 * Forgotten password (docs/11 §30a): National ID → code to the trusted
 * mobile → new password, on one route, built from the same steps as
 * activation. The whole state — including the opaque challenge reference —
 * lives in this component's memory: nothing goes to the URL, localStorage,
 * sessionStorage or a cookie, so a refresh simply starts over. A successful
 * reset signs in directly; there is no extra login step.
 */
export function PasswordResetFlow() {
  const signIn = useFamilySignIn();
  const [state, setState] = useState<State>({ step: "IDENTIFIER", notice: null });

  const restart = (notice: string | null = null) => setState({ step: "IDENTIFIER", notice });

  function started({ challenge, ...timers }: ActivationStart) {
    setState({ step: "OTP", challenge, timers });
  }

  function completed(user: FamilyUser) {
    setState({ step: "DONE" });
    signIn(user);
  }

  return (
    <FamilyAuthCard
      step={state.step}
      progress={state.step === "DONE" ? null : `الخطوة ${STEP_NUMBER[state.step]} من 3`}
      below={
        state.step === "IDENTIFIER" && (
          <p>
            تذكّرت كلمة المرور؟{" "}
            <Link href="/family/login" className={textLinkClass}>
              تسجيل الدخول
            </Link>
          </p>
        )
      }
    >
      {state.step === "IDENTIFIER" && (
        <NationalIdStep
          title="استعادة كلمة المرور"
          description="أدخل رقم الهوية لإرسال رمز تحقق إلى رقم الجوال الموثّق المسجّل لدينا."
          submitLabel="متابعة"
          notice={state.notice}
          start={startPasswordReset}
          onStarted={started}
        />
      )}
      {state.step === "OTP" && (
        // Keyed by the reference: a new challenge is a new screen.
        <OtpStep
          key={state.challenge}
          challenge={state.challenge}
          timers={state.timers}
          verify={verifyPasswordReset}
          resend={resendPasswordReset}
          onVerified={() => setState({ step: "NEW_PASSWORD", challenge: state.challenge })}
          onRestart={() => restart()}
        />
      )}
      {state.step === "NEW_PASSWORD" && (
        <PasswordStep
          title="كلمة مرور جديدة"
          passwordLabel="كلمة المرور الجديدة"
          submitLabel="حفظ كلمة المرور"
          pendingLabel="جارٍ حفظ كلمة المرور…"
          restartMessage="تعذّر تغيير كلمة المرور. ابدأ من جديد."
          complete={(password, confirmation) => completePasswordReset(state.challenge, password, confirmation)}
          onCompleted={completed}
          onRestart={restart}
        />
      )}
      {state.step === "DONE" && (
        <div className="flex flex-col items-center gap-3 py-8 text-center" role="status">
          <Loader2 className="size-6 animate-spin text-brand-700" aria-hidden />
          <p className="text-[15px] font-medium text-foreground">تم تغيير كلمة المرور. جارٍ فتح بوابة الأسرة…</p>
        </div>
      )}
    </FamilyAuthCard>
  );
}
