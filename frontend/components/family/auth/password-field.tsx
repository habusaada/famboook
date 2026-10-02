"use client";

import { forwardRef } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "@/components/ui/input";
import { fieldClass } from "@/components/family/auth/auth-parts";
import { cn } from "@/lib/utils";

type Props = Omit<React.ComponentProps<"input">, "type"> & {
  visible: boolean;
  /** Omit to render the field without its own show/hide control. */
  onToggle?: () => void;
};

/**
 * A password input with the portal's show/hide control (40px target). The
 * toggle only changes how the typed value is displayed, locally.
 */
export const PasswordField = forwardRef<HTMLInputElement, Props>(function PasswordField(
  { visible, onToggle, className, ...props },
  ref
) {
  return (
    <div className="relative">
      <Input ref={ref} type={visible ? "text" : "password"} dir="ltr" className={cn(fieldClass, onToggle && "ps-12", className)} {...props} />
      {onToggle && (
        <button
          type="button"
          onClick={onToggle}
          aria-label={visible ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
          aria-pressed={visible}
          className="absolute start-1 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
        >
          {visible ? <EyeOff className="size-[18px]" aria-hidden /> : <Eye className="size-[18px]" aria-hidden />}
        </button>
      )}
    </div>
  );
});
