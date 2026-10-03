"use client";

import { useState } from "react";
import { Loader2, Smartphone } from "lucide-react";
import { Button } from "@/components/ui/button";
import { FormAlert, StepHeading, primaryButtonClass } from "@/components/family/auth/auth-parts";
import { type ActivationStart, readFamilyAuthError } from "@/lib/api/family-auth";

type Props = {
  /** Only ever the masked form, 05*****123: the browser never holds the full number. */
  maskedMobile: string;
  /** Sends the code to the stored number; no number is ever sent from here. */
  send: () => Promise<ActivationStart>;
  onSent: (start: ActivationStart) => void;
  onNotMine: () => void;
  onRestart: (notice: string) => void;
};

/**
 * First self-activation (FP-ADR-053), step 2: the registered number, masked,
 * and one question. Confirming only asks for the code — it proves nothing:
 * the number becomes trusted only when the code sent to it is entered.
 * There is no way to type another number here.
 */
export function MobileConfirmStep({ maskedMobile, send, onSent, onNotMine, onRestart }: Props) {
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    if (sending) return;
    setError(null);
    setSending(true);
    try {
      onSent(await send());
    } catch (e) {
      const failure = readFamilyAuthError(e);
      // A used or expired confirmation: only a fresh start helps.
      if (failure.code === "OTP_INVALID") {
        onRestart("انتهت صلاحية هذه الخطوة. أدخل رقم الهوية من جديد.");
        return;
      }
      setError(failure.message);
      setSending(false);
    }
  }

  return (
    <section aria-labelledby="family-mobile-title">
      {/* The same words for every identifier: nothing here says whether this
          one belongs to an eligible household head (FP-ADR-053). */}
      <StepHeading id="family-mobile-title" title="تأكيد رقم الجوال">
        سيتم إرسال رمز التحقق إلى رقم الجوال المسجّل لرب الأسرة:
      </StepHeading>

      <div className="flex flex-col gap-5">
        <FormAlert message={error} />

        <div className="flex items-center justify-center gap-3 rounded-xl border border-border bg-surface-1 px-4 py-4">
          <Smartphone className="size-5 shrink-0 text-brand-700" aria-hidden />
          <span dir="ltr" className="text-xl font-semibold tracking-widest tabular-nums text-foreground" data-masked-mobile>
            {maskedMobile}
          </span>
        </div>

        <p className="text-center text-[15px] leading-relaxed text-foreground">هل هذا رقمك ويمكنك استقبال رمز التحقق عليه؟</p>

        <Button type="button" className={primaryButtonClass} disabled={sending} onClick={confirm}>
          {sending ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              جارٍ إرسال الرمز…
            </>
          ) : (
            "نعم، أرسل رمز التحقق"
          )}
        </Button>

        <Button type="button" variant="ghost" className="h-11 rounded-xl text-sm text-muted-foreground" disabled={sending} onClick={onNotMine}>
          ليس رقمي أو لا أستطيع استقبال الرمز
        </Button>
      </div>
    </section>
  );
}
