"use client";

import { useEffect, useState } from "react";
import { BadgeCheck, Info, Loader2, ShieldAlert } from "lucide-react";
import { FamilyBrand } from "@/components/family/family-brand";
import { ApiError } from "@/lib/api/client";
import { type VerifiedFamilyCard, verifyCredential } from "@/lib/api/credentials";
import { formatDateLong } from "@/lib/utils/date";
import {
  CARD_NOT_VERIFIABLE,
  CARD_VERIFY_RATE_LIMITED,
  FAMILY_CARD_DISCLAIMER,
} from "@/lib/utils/family-card";

type State =
  | { kind: "loading" }
  | { kind: "verified"; card: VerifiedFamilyCard }
  | { kind: "failed"; message: string };

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function Verified({ card }: { card: VerifiedFamilyCard }) {
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-5" aria-labelledby="verify-result-title" data-verify-result="valid">
      <h2 id="verify-result-title" className="flex items-center gap-2 text-lg font-bold text-success">
        <BadgeCheck className="size-5" aria-hidden />
        بطاقة صالحة
      </h2>
      <dl className="mt-2 divide-y divide-stroke-subtle">
        <Row label="رقم البطاقة" field="credential_number">
          <bdi dir="ltr" className="font-mono tracking-wide">
            {card.credential_number}
          </bdi>
        </Row>
        <Row label="رمز الأسرة" field="family_code">
          <bdi dir="ltr" className="font-mono">
            {card.family_code}
          </bdi>
        </Row>
        <Row label="تاريخ الإصدار" field="issued_at">
          <span className="tabular-nums">{formatDateLong(card.issued_at)}</span>
        </Row>
        {card.clan && (
          <Row label="العشيرة" field="clan">
            <bdi>{card.clan}</bdi>
          </Row>
        )}
        {card.branch && (
          <Row label="الفرع" field="branch">
            <bdi>{card.branch}</bdi>
          </Row>
        )}
        {card.head_name && (
          <Row label="رب الأسرة الحالي" field="head_name">
            <bdi>{card.head_name}</bdi>
          </Row>
        )}
      </dl>
    </section>
  );
}

/**
 * Public verification of a Digital Family Card (docs/11 §19, FP-ADR-070):
 * the browser posts the token to the API in the request BODY and renders
 * only the approved fields. Every failure is the same message — it never
 * says whether the token is malformed, unknown, revoked or belongs to an
 * inactive Family. Nothing is cached or stored.
 */
export function CredentialVerification({ token }: { token: string }) {
  const [state, setState] = useState<State>({ kind: "loading" });

  useEffect(() => {
    let current = true;
    verifyCredential(token)
      .then((card) => current && setState({ kind: "verified", card }))
      .catch((error: unknown) => {
        if (!current) return;
        const limited = error instanceof ApiError && error.status === 429;
        setState({ kind: "failed", message: limited ? CARD_VERIFY_RATE_LIMITED : CARD_NOT_VERIFIABLE });
      });

    return () => {
      current = false;
    };
  }, [token]);

  return (
    <main className="mx-auto flex min-h-svh w-full max-w-md flex-col gap-5 px-4 py-8">
      <header className="flex flex-col items-center gap-3 text-center">
        <FamilyBrand />
        <h1 className="text-xl font-bold text-foreground">التحقق من بطاقة الأسرة الرقمية</h1>
      </header>

      <div aria-live="polite" aria-busy={state.kind === "loading"}>
        {state.kind === "loading" && (
          <div className="flex justify-center py-10" data-verify-loading>
            <Loader2 className="size-6 animate-spin text-brand-700" aria-label="جارٍ التحقق من البطاقة" />
          </div>
        )}
        {state.kind === "verified" && <Verified card={state.card} />}
        {state.kind === "failed" && (
          <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-verify-result="failed">
            <ShieldAlert className="size-7 text-muted-foreground" aria-hidden />
            <p className="text-base font-semibold text-foreground" role="alert">
              {state.message}
            </p>
          </section>
        )}
      </div>

      <p className="flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-verify-disclaimer>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        {FAMILY_CARD_DISCLAIMER}
      </p>
    </main>
  );
}
