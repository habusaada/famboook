"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { FieldError, FormAlert, StepHeading, primaryButtonClass } from "@/components/family/auth/auth-parts";
import { PasswordField } from "@/components/family/auth/password-field";
import { type FamilyAuthErrorCode, type FamilyUser, readFamilyAuthError } from "@/lib/api/family-auth";
import { FAMILY_PASSWORD_MIN_LENGTH, type PasswordValues, passwordSchema } from "@/lib/schemas/family-auth";

/** After these the verified grant is gone: only a fresh start helps. */
const TERMINAL: readonly FamilyAuthErrorCode[] = [
  "GRANT_EXPIRED",
  "OTP_LOCKED",
  "OTP_INVALID",
  "OTP_EXPIRED",
  "ACTIVATION_FAILED",
  "RESET_FAILED",
];

type Props = {
  title: string;
  passwordLabel: string;
  submitLabel: string;
  pendingLabel: string;
  /** Shown when the server refuses the challenge reference itself. */
  restartMessage: string;
  /** The completion endpoint of the workflow: activation or password reset. */
  complete: (password: string, confirmation: string) => Promise<FamilyUser>;
  onCompleted: (user: FamilyUser) => void;
  onRestart: (message: string) => void;
};

/**
 * The password step, shared by activation (the first password) and password
 * reset (a new one). The only rules shown are the ones the server enforces —
 * a minimum length and a matching confirmation; a passphrase is welcome. The
 * password stays in this form's memory and the request body.
 */
export function PasswordStep({ title, passwordLabel, submitLabel, pendingLabel, restartMessage, complete, onCompleted, onRestart }: Props) {
  const [error, setError] = useState<string | null>(null);
  // Presentation only: reveals both typed passwords locally.
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
      onCompleted(await complete(values.password, values.password_confirmation));
    } catch (e) {
      const failure = readFamilyAuthError(e);
      if (failure.code && TERMINAL.includes(failure.code)) {
        onRestart(failure.message);
        return;
      }
      const field = failure.fields.password?.[0];
      if (field) setFieldError("password", { message: field });
      else if (failure.fields.challenge) onRestart(restartMessage);
      else setError(failure.message);
    }
  });

  return (
    <section aria-labelledby="family-password-title">
      <StepHeading id="family-password-title" title={title}>
        اختر كلمة مرور لا تقل عن {FAMILY_PASSWORD_MIN_LENGTH} أحرف. يمكنك استخدام عبارة سهلة التذكّر.
      </StepHeading>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-busy={isSubmitting}>
        <FormAlert message={error} />

        <div className="flex flex-col gap-2">
          <Label htmlFor="family-password" className="text-sm font-medium">
            {passwordLabel}
          </Label>
          <PasswordField
            id="family-password"
            autoComplete="new-password"
            autoFocus
            visible={visible}
            onToggle={() => setVisible((v) => !v)}
            aria-invalid={errors.password ? true : undefined}
            aria-describedby={errors.password ? "family-password-error" : undefined}
            {...register("password")}
          />
          <FieldError id="family-password-error" message={errors.password?.message} />
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="family-password-confirmation" className="text-sm font-medium">
            تأكيد كلمة المرور
          </Label>
          <PasswordField
            id="family-password-confirmation"
            autoComplete="new-password"
            visible={visible}
            aria-invalid={errors.password_confirmation ? true : undefined}
            aria-describedby={errors.password_confirmation ? "family-password-confirmation-error" : undefined}
            {...register("password_confirmation")}
          />
          <FieldError id="family-password-confirmation-error" message={errors.password_confirmation?.message} />
        </div>

        <Button type="submit" disabled={isSubmitting} className={primaryButtonClass}>
          {isSubmitting ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              {pendingLabel}
            </>
          ) : (
            submitLabel
          )}
        </Button>
      </form>
    </section>
  );
}
