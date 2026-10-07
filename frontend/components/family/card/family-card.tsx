"use client";

import Link from "next/link";
import { AlertCircle, ArrowRight, Info, QrCode, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FamilyBrand } from "@/components/family/family-brand";
import { ApiError } from "@/lib/api/client";
import { type FamilyCard as FamilyCardData, useFamilyCardQuery } from "@/lib/api/family-card";
import { isAccessFailure } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { FAMILY_CARD_DISCLAIMER, FAMILY_CARD_TITLE } from "@/lib/utils/family-card";

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function CardFace({ card }: { card: FamilyCardData }) {
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-5" aria-labelledby="family-card-face-title" data-family-card>
      <div className="flex items-center justify-between gap-3">
        <FamilyBrand />
        <span className="text-xs font-semibold text-brand-800">{FAMILY_CARD_TITLE}</span>
      </div>
      <h2 id="family-card-face-title" className="mt-4 text-lg leading-snug font-bold text-foreground">
        <bdi>{card.head_name}</bdi>
      </h2>
      <dl className="mt-1 divide-y divide-stroke-subtle">
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
        <Row label="تاريخ الإصدار" field="issued_at">
          <span className="tabular-nums">{formatDateLong(card.issued_at)}</span>
        </Row>
      </dl>

      <div className="mt-4 flex flex-col items-center gap-2 rounded-xl border border-border bg-white p-4" data-family-card-qr>
        {card.qr_available && card.qr ? (
          // A server-generated SVG data URI; never injected as markup.
          // eslint-disable-next-line @next/next/no-img-element
          <img src={card.qr} alt="رمز QR للتحقق من بطاقة الأسرة الرقمية" className="size-56" />
        ) : (
          <p className="flex items-center gap-2 py-6 text-center text-sm text-muted-foreground" data-family-card-no-qr>
            <QrCode className="size-5 shrink-0" aria-hidden />
            تعذّر عرض رمز التحقق حاليًا. يُرجى مراجعة إدارة السجل.
          </p>
        )}
      </div>
      {card.qr_available && <p className="mt-2 text-center text-[13px] text-muted-foreground">اعرض رمز QR عند طلب التحقق من بطاقة أسرتك.</p>}
    </section>
  );
}

/**
 * «بطاقة الأسرة الرقمية» (PWA-8.2, docs/11 FP-ADR-070): the household's card,
 * ensured by the server on first open, with its verification QR. The card
 * belongs to the Family; the head shown is the current one. A digital
 * verification credential inside Famboook — never an official identity
 * document. No print / PDF control until PWA-8.3.
 */
export function FamilyCard() {
  const query = useFamilyCardQuery();
  const unavailable = query.error instanceof ApiError && query.error.status === 503;

  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          href="/family"
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          data-family-card-back
        >
          <ArrowRight className="size-4" aria-hidden />
          الرئيسية
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">{FAMILY_CARD_TITLE}</h1>
      </header>

      <div aria-busy={query.isPending}>
        {query.data ? (
          <CardFace card={query.data} />
        ) : query.isError ? (
          !isAccessFailure(query.error) && (
            <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-card-error>
              <AlertCircle className="size-6 text-danger" aria-hidden />
              <p className="text-sm font-medium text-foreground" role="alert">
                {unavailable ? "بطاقة الأسرة الرقمية غير متاحة حاليًا." : "تعذّر تحميل البطاقة"}
              </p>
              {!unavailable && (
                <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
                  <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                  إعادة المحاولة
                </Button>
              )}
            </section>
          )
        ) : (
          <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface-1 p-5" data-family-card-loading>
            <p role="status" className="sr-only">
              جارٍ تحميل البطاقة
            </p>
            <Skeleton className="h-6 w-40" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="mx-auto size-56 rounded-xl" />
          </div>
        )}
      </div>

      <p className="flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-family-card-disclaimer>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        {FAMILY_CARD_DISCLAIMER}
      </p>
    </div>
  );
}
