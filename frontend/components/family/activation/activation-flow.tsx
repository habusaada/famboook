"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";
import { FamilyBrand } from "@/components/family/family-brand";
import { StepNationalId } from "@/components/family/activation/step-national-id";
import { StepOtp } from "@/components/family/activation/step-otp";
import { StepPassword } from "@/components/family/activation/step-password";
import { type ActivationStart, type ChallengeTimers, FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";

// The step is presentation; the SERVER decides what a challenge may do next.
// A browser cannot skip to the password: without a verified challenge the
// completion is refused.
type State =
  | { step: "NATIONAL_ID"; notice: string | null }
  | { step: "OTP"; challenge: string; timers: ChallengeTimers }
  | { step: "PASSWORD"; challenge: string }
  | { step: "ACTIVATED" };

const STEP_NUMBER = { NATIONAL_ID: 1, OTP: 2, PASSWORD: 3, ACTIVATED: 3 } as const;

/**
 * Family account activation (docs/11 §30a): National ID → code → password,
 * on one route. The whole state — including the opaque challenge reference —
 * lives in this component's memory: nothing goes to the URL, localStorage,
 * sessionStorage or a cookie, so a refresh simply starts over.
 */
export function ActivationFlow() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [state, setState] = useState<State>({ step: "NATIONAL_ID", notice: null });

  const restart = (notice: string | null = null) => setState({ step: "NATIONAL_ID", notice });

  function started({ challenge, ...timers }: ActivationStart) {
    setState({ step: "OTP", challenge, timers });
  }

  function activated(user: FamilyUser) {
    setState({ step: "ACTIVATED" });
    // Nothing cached for a previous session survives into this one.
    queryClient.clear();
    queryClient.setQueryData(FAMILY_ME_QUERY_KEY, user);
    router.replace("/family");
  }

  return (
    <main className="flex min-h-svh flex-col items-center px-4 py-8 sm:justify-center sm:py-12" data-activation-step={state.step}>
      <div className="flex w-full max-w-[400px] flex-col">
        <FamilyBrand size="lg" className="mb-7 justify-center" />

        <div className="rounded-2xl border border-border bg-surface-1 p-5 sm:p-7">
          {state.step !== "ACTIVATED" && (
            <p className="mb-3 text-xs font-medium text-subtle-foreground" data-activation-progress>
              الخطوة {STEP_NUMBER[state.step]} من 3
            </p>
          )}

          {state.step === "NATIONAL_ID" && <StepNationalId notice={state.notice} onStarted={started} />}
          {state.step === "OTP" && (
            // Keyed by the reference: a new challenge is a new screen.
            <StepOtp
              key={state.challenge}
              challenge={state.challenge}
              timers={state.timers}
              onVerified={() => setState({ step: "PASSWORD", challenge: state.challenge })}
              onRestart={() => restart()}
            />
          )}
          {state.step === "PASSWORD" && <StepPassword challenge={state.challenge} onActivated={activated} onRestart={restart} />}
          {state.step === "ACTIVATED" && (
            <div className="flex flex-col items-center gap-3 py-8 text-center" role="status">
              <Loader2 className="size-6 animate-spin text-brand-700" aria-hidden />
              <p className="text-[15px] font-medium text-foreground">تم تفعيل الحساب. جارٍ فتح بوابة الأسرة…</p>
            </div>
          )}
        </div>

        <p className="mt-4 text-center text-xs leading-relaxed text-subtle-foreground">
          لا تشارك رمز التحقق أو كلمة المرور مع أي شخص.
        </p>
      </div>
    </main>
  );
}
