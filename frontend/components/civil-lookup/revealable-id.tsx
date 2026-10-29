"use client";

import { useEffect, useRef, useState } from "react";
import { Eye, EyeOff, LoaderCircle } from "lucide-react";
import { civilLookupErrorMessage, revealCivilLookupId, useRefreshStatus } from "@/lib/api/civil-lookup";
import type { CivilLookupSpace } from "@/lib/types/api/civil-lookup";

type State = { status: "hidden" } | { status: "loading" } | { status: "shown"; value: string } | { status: "failed"; error: unknown };

/**
 * FB-CIVIL-02G — a National ID masked as the server returned it, with its own
 * Eye control: the same interaction as SensitiveValue on «بياناتي الشخصية»
 * (components/family/account/sensitive-value.tsx) — same states, control and
 * accessible names — for the Civil Registry Lookup of every space. Showing
 * asks the server for that ONE value by its reference; hiding discards it at
 * once and showing again asks again. Nothing is cached, persisted, copied or
 * put in an attribute; an answer that arrives after a hide, or after the row
 * is gone (another record, another page), is dropped. The quota indicator is
 * refreshed after each reveal (a first reveal is counted).
 */
export function RevealableId({
  masked,
  revealRef,
  space,
  subject,
  field,
}: {
  masked: string;
  revealRef: string;
  space: CivilLookupSpace;
  subject?: string;
  field?: string;
}) {
  const refreshStatus = useRefreshStatus();
  const [state, setState] = useState<State>({ status: "hidden" });
  const ticket = useRef(0);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const label = subject ? `رقم الهوية — ${subject}` : "رقم الهوية";
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
      const value = await revealCivilLookupId(space, revealRef);
      if (mounted.current && ticket.current === mine) setState({ status: "shown", value });
    } catch (error) {
      if (mounted.current && ticket.current === mine) setState({ status: "failed", error });
    } finally {
      refreshStatus();
    }
  }

  return (
    <span className="inline-flex flex-col items-start gap-1" data-revealable-id data-field={field}>
      <span className="flex items-center gap-1.5">
        {shown ? (
          <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-revealed>
            {state.value}
          </bdi>
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
          data-reveal="NATIONAL_ID"
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
      {state.status === "failed" && (
        <span role="alert" className="text-xs font-normal text-danger" data-reveal-error>
          {civilLookupErrorMessage(state.error)}
        </span>
      )}
    </span>
  );
}

/** A National ID as returned: complete as is, or masked with the Eye control when a reveal is offered for it. */
export function CivilLookupId({
  value,
  revealRef,
  space,
  canReveal,
  subject,
  className,
  field,
}: {
  value: string;
  revealRef?: string;
  space: CivilLookupSpace;
  canReveal: boolean;
  subject?: string;
  className?: string;
  field?: string;
}) {
  if (value.includes("*") && canReveal && revealRef) {
    return <RevealableId key={revealRef} masked={value} revealRef={revealRef} space={space} subject={subject} field={field} />;
  }
  return (
    <bdi dir="ltr" className={className ?? "tabular-nums"} data-field={field}>
      {value}
    </bdi>
  );
}
