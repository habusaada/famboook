"use client";

import { useState } from "react";
import Link from "next/link";
import { Loader2 } from "lucide-react";
import { MobileConfirmStep } from "@/components/family/activation/mobile-confirm-step";
import { FamilyAuthCard, textLinkClass } from "@/components/family/auth/auth-parts";
import { NationalIdStep } from "@/components/family/auth/national-id-step";
import { OtpStep } from "@/components/family/auth/otp-step";
import { PasswordStep } from "@/components/family/auth/password-step";
import { useFamilySignIn } from "@/components/family/auth/use-family-sign-in";
import {
  type ActivationConfirmation,
  type ActivationStart,
  type ChallengeTimers,
  type FamilyUser,
  completeActivation,
  resendActivation,
  sendActivationCode,
  startActivation,
  verifyActivation,
} from "@/lib/api/family-auth";

// The step is presentation; the SERVER decides what a challenge may do next.
// A browser cannot skip to the password: without a verified challenge the
// completion is refused.
type State =
  | { step: "NATIONAL_ID"; notice: string | null }
  | { step: "CONFIRM_MOBILE"; confirmation: string; maskedMobile: string }
  | { step: "OTP"; challenge: string; timers: ChallengeTimers; maskedMobile: string }
  | { step: "PASSWORD"; challenge: string }
  | { step: "ACTIVATED" };

const STEP_NUMBER = { NATIONAL_ID: 1, CONFIRM_MOBILE: 2, OTP: 3, PASSWORD: 4 } as const;

const NOT_MINE =
  "لا يمكن تغيير رقم الجوال أثناء التفعيل. لتحديث رقمك المسجّل، يرجى التواصل مع إدارة شؤون العائلة، ثم العودة لتفعيل الحساب.";

/**
 * Family account activation (docs/11 §30a, FP-ADR-053): National ID →
 * confirm the masked registered number → code → password, on one route. The
 * whole state — including the opaque references and the masked number —
 * lives in this component's memory: nothing goes to the URL, localStorage,
 * sessionStorage or a cookie, so a refresh simply starts over. The browser
 * never receives the full number and never sends one.
 */
export function ActivationFlow() {
  const signIn = useFamilySignIn();
  const [state, setState] = useState<State>({ step: "NATIONAL_ID", notice: null });

  const restart = (notice: string | null = null) => setState({ step: "NATIONAL_ID", notice });

  function confirmed({ confirmation, masked_mobile }: ActivationConfirmation) {
    setState({ step: "CONFIRM_MOBILE", confirmation, maskedMobile: masked_mobile });
  }

  function sent(maskedMobile: string, { challenge, ...timers }: ActivationStart) {
    setState({ step: "OTP", challenge, timers, maskedMobile });
  }

  function activated(user: FamilyUser) {
    setState({ step: "ACTIVATED" });
    signIn(user);
  }

  return (
    <FamilyAuthCard
      step={state.step}
      progress={state.step === "ACTIVATED" ? null : `الخطوة ${STEP_NUMBER[state.step]} من 4`}
      below={
        state.step === "NATIONAL_ID" && (
          <p>
            لديك حساب بالفعل؟{" "}
            <Link href="/family/login" className={textLinkClass}>
              تسجيل الدخول
            </Link>
          </p>
        )
      }
    >
      {state.step === "NATIONAL_ID" && (
        <NationalIdStep
          title="مرحبًا بك في فامبوك"
          description="فعّل حساب أسرتك للوصول إلى بيانات الأسرة وخدماتها الرقمية."
          submitLabel="متابعة وتفعيل الحساب"
          notice={state.notice}
          start={startActivation}
          onStarted={confirmed}
        />
      )}
      {state.step === "CONFIRM_MOBILE" && (
        <MobileConfirmStep
          key={state.confirmation}
          maskedMobile={state.maskedMobile}
          send={() => sendActivationCode(state.confirmation)}
          onSent={(start) => sent(state.maskedMobile, start)}
          onNotMine={() => restart(NOT_MINE)}
          onRestart={restart}
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
          description={
            <>
              أرسلنا رمز تحقق إلى الرقم{" "}
              <span dir="ltr" className="font-semibold tabular-nums text-foreground">
                {state.maskedMobile}
              </span>{" "}
              إذا كانت البيانات مطابقة لسجلاتنا.
            </>
          }
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
