"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { FamilyAuthCard } from "@/components/family/auth/auth-parts";
import { NationalIdStep } from "@/components/family/auth/national-id-step";
import { OtpStep } from "@/components/family/auth/otp-step";
import { PasswordStep } from "@/components/family/auth/password-step";
import { useFamilySignIn } from "@/components/family/auth/use-family-sign-in";
import {
  type ActivationStart,
  type ChallengeTimers,
  type FamilyUser,
  completeActivation,
  resendActivation,
  startActivation,
  verifyActivation,
} from "@/lib/api/family-auth";

// The step is presentation; the SERVER decides what a challenge may do next.
// A browser cannot skip to the password: without a verified challenge the
// completion is refused.
type State =
  | { step: "NATIONAL_ID"; notice: string | null }
  | { step: "OTP"; challenge: string; timers: ChallengeTimers }
  | { step: "PASSWORD"; challenge: string }
  | { step: "ACTIVATED" };

const STEP_NUMBER = { NATIONAL_ID: 1, OTP: 2, PASSWORD: 3 } as const;

/**
 * Family account activation (docs/11 §30a): National ID → code → password,
 * on one route. The whole state — including the opaque challenge reference —
 * lives in this component's memory: nothing goes to the URL, localStorage,
 * sessionStorage or a cookie, so a refresh simply starts over.
 */
export function ActivationFlow() {
  const signIn = useFamilySignIn();
  const [state, setState] = useState<State>({ step: "NATIONAL_ID", notice: null });

  const restart = (notice: string | null = null) => setState({ step: "NATIONAL_ID", notice });

  function started({ challenge, ...timers }: ActivationStart) {
    setState({ step: "OTP", challenge, timers });
  }

  function activated(user: FamilyUser) {
    setState({ step: "ACTIVATED" });
    signIn(user);
  }

  return (
    <FamilyAuthCard step={state.step} progress={state.step === "ACTIVATED" ? null : `الخطوة ${STEP_NUMBER[state.step]} من 3`}>
      {state.step === "NATIONAL_ID" && (
        <NationalIdStep
          title="مرحبًا بك في فامبوك"
          description="فعّل حساب أسرتك للوصول إلى بيانات الأسرة وخدماتها الرقمية."
          submitLabel="متابعة وتفعيل الحساب"
          notice={state.notice}
          start={startActivation}
          onStarted={started}
        />
      )}
      {state.step === "OTP" && (
        // Keyed by the reference: a new challenge is a new screen.
        <OtpStep
          key={state.challenge}
          challenge={state.challenge}
          timers={state.timers}
          verify={verifyActivation}
          resend={resendActivation}
          onVerified={() => setState({ step: "PASSWORD", challenge: state.challenge })}
          onRestart={() => restart()}
        />
      )}
      {state.step === "PASSWORD" && (
        <PasswordStep
          title="إنشاء كلمة المرور"
          passwordLabel="كلمة المرور"
          submitLabel="تفعيل الحساب"
          pendingLabel="جارٍ تفعيل الحساب…"
          restartMessage="تعذّر إكمال التفعيل. ابدأ من جديد."
          complete={(password, confirmation) => completeActivation(state.challenge, password, confirmation)}
          onCompleted={activated}
          onRestart={restart}
        />
      )}
      {state.step === "ACTIVATED" && (
        <div className="flex flex-col items-center gap-3 py-8 text-center" role="status">
          <Loader2 className="size-6 animate-spin text-brand-700" aria-hidden />
          <p className="text-[15px] font-medium text-foreground">تم تفعيل الحساب. جارٍ فتح بوابة الأسرة…</p>
        </div>
      )}
    </FamilyAuthCard>
  );
}
