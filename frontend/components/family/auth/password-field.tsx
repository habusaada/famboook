"use client";

import { forwardRef } from "react";
import { Eye, EyeOff, LockKeyhole } from "lucide-react";
import { Input } from "@/components/ui/input";
import { fieldClass } from "@/components/family/auth/auth-parts";
import { cn } from "@/lib/utils";

type Props = Omit<React.ComponentProps<"input">, "type"> & {
  visible: boolean;
  /** Omit to render the field without its own show/hide control. */
  onToggle?: () => void;
  /**
   * Opt-in layout with a decorative lock icon on the right and the show/hide
   * control on the left. Physical sides on purpose: the input's own direction
   * (ltr or auto) would flip logical padding. Without it, the show/hide
   * control is on the right and the input reserves the right side for it.
   */
  withLockIcon?: boolean;
};

/**
 * A password input with the portal's show/hide control (40px target). The
 * toggle only changes how the typed value is displayed, locally.
 */
export const PasswordField = forwardRef<HTMLInputElement, Props>(function PasswordField(
  { visible, onToggle, withLockIcon = false, className, ...props },
  ref
) {
  return (
    <div className="relative">
      <Input
        ref={ref}
        type={visible ? "text" : "password"}
        dir="ltr"
        className={cn(fieldClass, withLockIcon ? ["pr-11", onToggle && "pl-12"] : onToggle && "pr-12", className)}
        {...props}
      />
      {withLockIcon && (
        <LockKeyhole
          className="pointer-events-none absolute right-3.5 top-1/2 size-[18px] -translate-y-1/2 text-muted-foreground"
          aria-hidden
          data-field-icon="lock"
        />
      )}
      {onToggle && (
        <button
          type="button"
          onClick={onToggle}
          aria-label={visible ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
          aria-pressed={visible}
          className={cn(
            "absolute top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring",
            withLockIcon ? "left-1" : "right-1"
          )}
        >
          {visible ? <EyeOff className="size-[18px]" aria-hidden /> : <Eye className="size-[18px]" aria-hidden />}
        </button>
      )}
    </div>
  );
});
