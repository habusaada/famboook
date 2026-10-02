"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError, FormAlert, StepHeading, fieldClass, primaryButtonClass } from "@/components/family/activation/activation-parts";
import { type ActivationStart, readActivationError, startActivation } from "@/lib/api/family-auth";
import { type NationalIdInput, type NationalIdValues, nationalIdSchema } from "@/lib/schemas/family-activation";
import { cn } from "@/lib/utils";

/**
 * Step 1: the National ID. The answer is the same for every well-formed
 * number, so nothing here says whether it is known or eligible; only a
 * malformed number is a field error.
 */
export function StepNationalId({ notice, onStarted }: { notice: string | null; onStarted: (start: ActivationStart) => void }) {
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
      onStarted(await startActivation(values.national_id));
    } catch (e) {
      const failure = readActivationError(e);
      const field = failure.fields.national_id?.[0];
      if (field) setFieldError("national_id", { message: field });
      else setError(failure.message);
    }
  });

  return (
    <section aria-labelledby="activation-title">
      <StepHeading id="activation-title" title="مرحبًا بك في فامبوك">
        فعّل حساب أسرتك للوصول إلى بيانات الأسرة وخدماتها الرقمية.
      </StepHeading>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-busy={isSubmitting}>
        <FormAlert message={notice} tone="info" />
        <FormAlert message={error} />

        <div className="flex flex-col gap-2">
          <Label htmlFor="activation-national-id" className="text-sm font-medium">
            رقم الهوية
          </Label>
          <Input
            id="activation-national-id"
            type="text"
            inputMode="numeric"
            dir="ltr"
            autoComplete="off"
            autoFocus
            className={cn(fieldClass, "text-start tracking-wide")}
            aria-invalid={errors.national_id ? true : undefined}
            aria-describedby={errors.national_id ? "activation-national-id-error" : undefined}
            {...register("national_id")}
          />
          <FieldError id="activation-national-id-error" message={errors.national_id?.message} />
        </div>

        <Button type="submit" disabled={isSubmitting} className={primaryButtonClass}>
          {isSubmitting ? (
            <>
              <Loader2 className="size-4 animate-spin" aria-hidden />
              جارٍ المتابعة…
            </>
          ) : (
            "متابعة وتفعيل الحساب"
          )}
        </Button>
      </form>
    </section>
  );
}
