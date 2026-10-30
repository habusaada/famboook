"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, FileDown, Info, Loader2, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FamilyCardFace } from "@/components/family/card/family-card-face";
import { ApiError } from "@/lib/api/client";
import { type FamilyCard as FamilyCardData, downloadFamilyCardPdf, useFamilyCardQuery } from "@/lib/api/family-card";
import { isAccessFailure } from "@/lib/api/family-household";
import { FAMILY_CARD_DISCLAIMER, FAMILY_CARD_TITLE } from "@/lib/utils/family-card";

/** What a failed download says; never why beyond what the owner can act on. */
function downloadErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 404) return "تغيّرت حالة البطاقة. أعيد تحميلها، حاول التنزيل مرة أخرى.";
    if (error.status === 409) return "تعذّر إنشاء رمز التحقق حاليًا. يُرجى مراجعة إدارة السجل.";
    if (error.status === 429) return "طلبات كثيرة. حاول بعد قليل.";
  }
  return "تعذّر تنزيل البطاقة. حاول مرة أخرى.";
}

/**
 * «تنزيل البطاقة PDF» (PWA-8.3): the printable card of the EXISTING ACTIVE
 * credential, through GET /api/v1/family/card/pdf — never an issuance. The
 * bytes are a transient Blob (no query cache, no browser storage). A 404
 * means the card changed meanwhile: the card is reloaded.
 */
function DownloadPdf({ card, onCardChanged }: { card: FamilyCardData; onCardChanged: () => void }) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function download() {
    setPending(true);
    setError(null);
    try {
      await downloadFamilyCardPdf(card.credential_number);
    } catch (failure) {
      setError(downloadErrorMessage(failure));
      if (failure instanceof ApiError && failure.status === 404) onCardChanged();
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="mt-4 flex flex-col items-stretch gap-2" data-family-card-download>
      <Button type="button" className="h-12 gap-2 rounded-xl text-sm font-semibold" onClick={download} disabled={pending} aria-busy={pending}>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <FileDown className="size-4" aria-hidden />}
        {pending ? "جارٍ تجهيز الملف…" : "تنزيل البطاقة PDF"}
      </Button>
      {error && (
        <p className="flex items-center justify-center gap-2 text-center text-sm text-danger" role="alert" data-family-card-download-error>
          <AlertCircle className="size-4 shrink-0" aria-hidden />
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * «بطاقة الأسرة الرقمية» (PWA-8.2, docs/11 FP-ADR-070): the household's card,
 * ensured by the server on first open, with its verification QR. The card
 * belongs to the Family; the head shown is the current one. A digital
 * verification credential inside Famboook — never an official identity
 * document. The PDF of the same card (PWA-8.3) is offered only with a QR.
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
          <>
            <FamilyCardFace card={query.data} />
            {query.data.qr_available && <DownloadPdf card={query.data} onCardChanged={() => void query.refetch()} />}
          </>
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
