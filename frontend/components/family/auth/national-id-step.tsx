"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError, FormAlert, StepHeading, fieldClass, primaryButtonClass } from "@/components/family/auth/auth-parts";
import { type ActivationStart, readFamilyAuthError } from "@/lib/api/family-auth";
import { type NationalIdInput, type NationalIdValues, nationalIdSchema } from "@/lib/schemas/family-auth";
import { cn } from "@/lib/utils";

type Props = {
  title: string;
  description: string;
  submitLabel: string;
  /** Why the flow came back here (an expired grant, a locked code…). */
  notice: string | null;
  /** The start endpoint of the workflow: activation or password reset. */
  start: (nationalId: string) => Promise<ActivationStart>;
  onStarted: (start: ActivationStart) => void;
};

/**
 * The first step of activation and of a password reset: the National ID.
 * The answer is the same for every well-formed number, so nothing here says
 * whether it is known or eligible; only a malformed number is a field error.
 */
export function NationalIdStep({ title, description, submitLabel, notice, start, onStarted }: Props) {
  const [error, setError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError: setFieldError,
    formState: { errors, isSubmitting },
  } = useForm<NationalIdInput, unknown, NationalIdValues>({
    resolver: zodResolver(nationalIdSchema),
    defaultValues: { national_id: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      onStarted(await start(values.national_id));
    } catch (e) {
      const failure = readFamilyAuthError(e);
      const field = failure.fields.national_id?.[0];
      if (field) setFieldError("national_id", { message: field });
      else setError(failure.message);
    }
  });

  return (
    <section aria-labelledby="family-identifier-title">
      <StepHeading id="family-identifier-title" title={title}>
        {description}
      </StepHeading>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-busy={isSubmitting}>
        <FormAlert message={notice} tone="info" />
        <FormAlert message={error} />

        <div className="flex flex-col gap-2">
          <Label htmlFor="family-national-id" className="text-sm font-medium">
            رقم الهوية
          </Label>
          <Input
            id="family-national-id"
            type="text"
            inputMode="numeric"
            dir="ltr"
            autoComplete="off"
            autoFocus
            className={cn(fieldClass, "text-start tracking-wide")}
            aria-invalid={errors.national_id ? true : undefined}
            aria-describedby={errors.national_id ? "family-national-id-error" : undefined}
            {...register("national_id")}
          />
          <FieldError id="family-national-id-error" message={errors.national_id?.message} />
        </div>

        <Button type="submit" disabled={isSubmitting} className={primaryButtonClass}>
          {isSubmitting ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              جارٍ المتابعة…
            </>
          ) : (
            submitLabel
          )}
        </Button>
      </form>
    </section>
  );
}
