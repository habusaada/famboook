import { QrCode } from "lucide-react";
import { FamilyBrand } from "@/components/family/family-brand";
import type { FamilyCard as FamilyCardData } from "@/lib/api/family-card";
import { formatDateLong } from "@/lib/utils/date";
import { FAMILY_CARD_TITLE } from "@/lib/utils/family-card";

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

/**
 * The face of «بطاقة الأسرة الرقمية», presentation only: no request, no session
 * (FB-WEB-02D-CARD). `qrSlot` replaces the QR block's content, so the public
 * landing page can show a non-functional placeholder; the Family Portal never
 * passes it. `headingAs` keeps the page's heading outline where the card sits
 * under another h2.
 */
export function FamilyCardFace({ card, headingAs: Heading = "h2", qrSlot }: { card: FamilyCardData; headingAs?: "h2" | "h3"; qrSlot?: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-border bg-surface-1 p-5" aria-labelledby="family-card-face-title" data-family-card>
      <div className="flex items-center justify-between gap-3">
        <FamilyBrand />
        <span className="text-xs font-semibold text-brand-800">{FAMILY_CARD_TITLE}</span>
      </div>
      <Heading id="family-card-face-title" className="mt-4 text-lg leading-snug font-bold text-foreground">
        <bdi>{card.head_name}</bdi>
      </Heading>
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
        {qrSlot ??
          (card.qr_available && card.qr ? (
            // A server-generated SVG data URI; never injected as markup.
            // eslint-disable-next-line @next/next/no-img-element
            <img src={card.qr} alt="رمز QR للتحقق من بطاقة الأسرة الرقمية" className="size-56" />
          ) : (
            <p className="flex items-center gap-2 py-6 text-center text-sm text-muted-foreground" data-family-card-no-qr>
              <QrCode className="size-5 shrink-0" aria-hidden />
              تعذّر عرض رمز التحقق حاليًا. يُرجى مراجعة إدارة السجل.
            </p>
          ))}
      </div>
      {!qrSlot && card.qr_available && <p className="mt-2 text-center text-[13px] text-muted-foreground">اعرض رمز QR عند طلب التحقق من بطاقة أسرتك.</p>}
    </section>
  );
}
