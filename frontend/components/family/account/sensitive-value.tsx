"use client";

import { useEffect, useRef, useState } from "react";
import { Eye, EyeOff, LoaderCircle } from "lucide-react";
import { ApiError } from "@/lib/api/client";
import { useFamilyAccessFailure } from "@/lib/api/family-household";
import { type SelfRevealField, revealSelfValue } from "@/lib/api/family-self";

/**
 * Field labels used in the control's accessible name, e.g. «إظهار رقم الهوية».
 */
export const REVEAL_LABELS: Record<SelfRevealField, string> = {
  NATIONAL_ID: "رقم الهوية",
  MOBILE: "رقم الجوال",
  ALTERNATE_MOBILE: "الجوال البديل",
};

export const REVEAL_ERROR = "تعذّر إظهار القيمة. حاول مجددًا.";
export const REVEAL_TOO_MANY = "طلبات إظهار كثيرة. حاول مجددًا بعد قليل.";

type State =
  | { status: "hidden" }
  | { status: "loading" }
  // The ONLY place the full value lives: this component's state, while shown.
  | { status: "shown"; value: string | null }
  | { status: "failed"; error: unknown };

/**
 * One own sensitive value (PWA-3B.2, docs/11 §23a): masked by default, with
 * its own Eye control. Showing asks the server for that one field; hiding
 * discards the full value at once, and showing again asks again. Nothing is
 * cached, persisted, copied or put in an attribute; an answer that arrives
 * after a hide or after the component is gone is dropped. Only this field's
 * control is busy while its request runs.
 */
export function SensitiveValue({ field, masked }: { field: SelfRevealField; masked: string }) {
  const [state, setState] = useState<State>({ status: "hidden" });
  // Incremented on every request and every hide: a late answer for an older
  // request, or for a field hidden meanwhile, is never shown.
  const ticket = useRef(0);
  // False once the component is gone: a late answer is then dropped.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  // A 401 / 403 belongs to the session/access flow, as for every family request.
  useFamilyAccessFailure(state.status === "failed" ? state.error : null);

  const label = REVEAL_LABELS[field];
  const shown = state.status === "shown";
  const loading = state.status === "loading";

  async function toggle() {
    if (loading) return;
    if (shown) {
      ticket.current++;
      setState({ status: "hidden" });
      return;
    }

    const mine = ++ticket.current;
    setState({ status: "loading" });
    try {
      const value = await revealSelfValue(field);
      if (mounted.current && ticket.current === mine) setState({ status: "shown", value });
    } catch (error) {
      if (mounted.current && ticket.current === mine) setState({ status: "failed", error });
    }
  }

  const failure =
    state.status === "failed" && !(state.error instanceof ApiError && (state.error.status === 401 || state.error.status === 403))
      ? state.error instanceof ApiError && state.error.status === 429
        ? REVEAL_TOO_MANY
        : REVEAL_ERROR
      : null;

  return (
    <span className="flex flex-col items-end gap-1">
      <span className="flex items-center gap-1.5">
        {shown ? (
          state.value === null ? (
            <span className="font-normal text-subtle-foreground">غير مسجّل</span>
          ) : (
            <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-revealed>
              {state.value}
            </bdi>
          )
        ) : (
          <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-masked>
            {masked}
          </bdi>
        )}
        <button
          type="button"
          onClick={toggle}
          disabled={loading}
          aria-label={shown ? `إخفاء ${label}` : `إظهار ${label}`}
          aria-pressed={shown}
          aria-busy={loading}
          className="flex size-10 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-60"
          data-reveal={field}
        >
          {loading ? (
            <LoaderCircle className="size-[18px] animate-spin" aria-hidden />
          ) : shown ? (
            <EyeOff className="size-[18px]" aria-hidden />
          ) : (
            <Eye className="size-[18px]" aria-hidden />
          )}
        </button>
      </span>
      {failure && (
        <span role="alert" className="text-xs font-normal text-danger" data-reveal-error>
          {failure}
        </span>
      )}
    </span>
  );
}
