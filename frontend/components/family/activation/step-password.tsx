"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Eye, EyeOff, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError, FormAlert, StepHeading, fieldClass, primaryButtonClass } from "@/components/family/activation/activation-parts";
import { type ActivationErrorCode, type FamilyUser, completeActivation, readActivationError } from "@/lib/api/family-auth";
import { FAMILY_PASSWORD_MIN_LENGTH, type PasswordValues, passwordSchema } from "@/lib/schemas/family-activation";
import { cn } from "@/lib/utils";

/** After these the verified grant is gone: only a fresh start helps. */
const TERMINAL: readonly ActivationErrorCode[] = ["GRANT_EXPIRED", "OTP_LOCKED", "OTP_INVALID", "OTP_EXPIRED", "ACTIVATION_FAILED"];

type Props = {
  challenge: string;
  onActivated: (user: FamilyUser) => void;
  onRestart: (message: string) => void;
};

/**
 * Step 3: the first password. The only rules shown are the ones the server
 * enforces — a minimum length and a matching confirmation; a passphrase is
 * welcome. The password stays in this form's memory and the request body.
 */
export function StepPassword({ challenge, onActivated, onRestart }: Props) {
  const [error, setError] = useState<string | null>(null);
  // Presentation only: reveals the typed passwords locally.
  const [visible, setVisible] = useState(false);
  const {
    register,
    handleSubmit,
    setError: setFieldError,
    formState: { errors, isSubmitting },
  } = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      onActivated(await completeActivation(challenge, values.password, values.password_confirmation));
    } catch (e) {
      const failure = readActivationError(e);
      if (failure.code && TERMINAL.includes(failure.code)) {
        onRestart(failure.message);
        return;
      }
      const field = failure.fields.password?.[0];
      if (field) setFieldError("password", { message: field });
      else if (failure.fields.challenge) onRestart("تعذّر إكمال التفعيل. ابدأ من جديد.");
      else setError(failure.message);
    }
  });

  const type = visible ? "text" : "password";

  return (
    <section aria-labelledby="activation-password-title">
      <StepHeading id="activation-password-title" title="إنشاء كلمة المرور">
        اختر كلمة مرور لا تقل عن {FAMILY_PASSWORD_MIN_LENGTH} أحرف. يمكنك استخدام عبارة سهلة التذكّر.
      </StepHeading>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-busy={isSubmitting}>
        <FormAlert message={error} />

        <div className="flex flex-col gap-2">
          <Label htmlFor="activation-password" className="text-sm font-medium">
            كلمة المرور
          </Label>
          <div className="relative">
            <Input
              id="activation-password"
              type={type}
              dir="ltr"
              autoComplete="new-password"
              autoFocus
              className={cn(fieldClass, "ps-12")}
              aria-invalid={errors.password ? true : undefined}
              aria-describedby={errors.password ? "activation-password-error" : undefined}
              {...register("password")}
            />
            <button
              type="button"
              onClick={() => setVisible((v) => !v)}
              aria-label={visible ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
              aria-pressed={visible}
              className="absolute start-1 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
            >
              {visible ? <EyeOff className="size-[18px]" aria-hidden /> : <Eye className="size-[18px]" aria-hidden />}
            </button>
          </div>
          <FieldError id="activation-password-error" message={errors.password?.message} />
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="activation-password-confirmation" className="text-sm font-medium">
            تأكيد كلمة المرور
          </Label>
          <Input
            id="activation-password-confirmation"
            type={type}
            dir="ltr"
            autoComplete="new-password"
            className={fieldClass}
            aria-invalid={errors.password_confirmation ? true : undefined}
            aria-describedby={errors.password_confirmation ? "activation-password-confirmation-error" : undefined}
            {...register("password_confirmation")}
          />
          <FieldError id="activation-password-confirmation-error" message={errors.password_confirmation?.message} />
        </div>

        <Button type="submit" disabled={isSubmitting} className={primaryButtonClass}>
          {isSubmitting ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              جارٍ تفعيل الحساب…
            </>
          ) : (
            "تفعيل الحساب"
          )}
        </Button>
      </form>
    </section>
  );
}
