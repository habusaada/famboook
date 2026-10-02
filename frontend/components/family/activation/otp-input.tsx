"use client";

import { cn } from "@/lib/utils";
import { OTP_LENGTH, normalizeOtp } from "@/lib/schemas/family-activation";

type Props = {
  id: string;
  value: string;
  onChange: (code: string) => void;
  disabled?: boolean;
  invalid?: boolean;
  describedBy?: string;
};

/**
 * One real input, six visual slots. The input covers the slots and holds the
 * whole code, so typing, pasting, SMS autofill (one-time-code) and screen
 * readers all meet a single ordinary text field; the slots are decoration.
 * No maxLength: a pasted "123 456" must reach the handler whole.
 */
export function OtpInput({ id, value, onChange, disabled, invalid, describedBy }: Props) {
  return (
    <div className="group relative" dir="ltr" data-otp>
      <div className="flex justify-between gap-2" aria-hidden>
        {Array.from({ length: OTP_LENGTH }, (_, index) => (
          <span
            key={index}
            className={cn(
              "flex h-14 flex-1 items-center justify-center rounded-xl border bg-surface-1 text-2xl font-semibold text-foreground tabular-nums transition-colors",
              invalid ? "border-danger" : "border-input",
              // The slot the next digit goes to, while the field has focus.
              !disabled && index === Math.min(value.length, OTP_LENGTH - 1) && "group-focus-within:border-ring group-focus-within:ring-3 group-focus-within:ring-ring/30",
              disabled && "bg-muted text-muted-foreground"
            )}
            data-otp-slot
          >
            {value[index] ?? ""}
          </span>
        ))}
      </div>
      <input
        id={id}
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        pattern="[0-9]*"
        dir="ltr"
        value={value}
        disabled={disabled}
        aria-invalid={invalid ? true : undefined}
        aria-describedby={describedBy}
        onChange={(event) => onChange(normalizeOtp(event.target.value))}
        className="absolute inset-0 h-full w-full cursor-text rounded-xl bg-transparent text-center text-transparent caret-transparent outline-none selection:bg-transparent disabled:cursor-not-allowed"
      />
    </div>
  );
}
