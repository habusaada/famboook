import Link from "next/link";
import { ArrowLeft, Check, CircleSlash, Download, Info, Network, QrCode, ShieldCheck, Smartphone, UsersRound, type LucideIcon } from "lucide-react";
import { FamilyCardFace } from "@/components/family/card/family-card-face";
import { IconBox, type Tone } from "@/components/shared/icon-box";
import { cn } from "@/lib/utils";
import { FAMILY_CARD_DISCLAIMER } from "@/lib/utils/family-card";
import { DEMO_CARD_LABEL, demoFamilyCard } from "./demo-card-fixture";
import { DIGITAL_CARD, LANDING_SECTION, LANDING_SHELL } from "./landing-content";

// FB-WEB-02D-CARD — the Digital Family Card on the homepage: the real card face
// (FamilyCardFace, also used by /family/card) with a synthetic fixture and a
// non-functional QR placeholder. It reads no session and calls no API. Every
// statement is what the code does today; nothing here is a government ID or a
// claim of eligibility (docs/adr/FB-WEB-02-LANDING-PAGE.md §7).

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

const FEATURE_VISUALS: Record<string, { icon: LucideIcon; tone: Tone }> = {
  identity: { icon: UsersRound, tone: "brand" },
  structure: { icon: Network, tone: "brand" },
  qr: { icon: QrCode, tone: "success" },
  access: { icon: Smartphone, tone: "info" },
  download: { icon: Download, tone: "info" },
  privacy: { icon: ShieldCheck, tone: "success" },
};

function DemoQrPlaceholder() {
  return (
    <div className="flex w-full flex-col items-center gap-2 rounded-lg border border-dashed border-border py-6 text-center" data-demo-card-qr-placeholder>
      <QrCode className="size-12 text-muted-foreground/50" aria-hidden />
      <p className="text-sm font-medium text-muted-foreground">رمز QR غير فعّال في هذا النموذج</p>
    </div>
  );
}

function DemoCard() {
  return (
    <div className="mx-auto flex w-full max-w-md flex-col gap-3 lg:sticky lg:top-24" data-demo-family-card>
      <p className="rounded-lg bg-warning-soft px-3 py-2 text-center text-sm font-bold text-foreground" data-demo-card-label>
        {DEMO_CARD_LABEL}
      </p>
      <div className="relative overflow-hidden rounded-2xl shadow-e3" data-portal="family">
        <FamilyCardFace card={demoFamilyCard} headingAs="h3" qrSlot={<DemoQrPlaceholder />} />
        <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center select-none">
          <span className="rotate-[-24deg] text-5xl font-bold whitespace-nowrap text-brand-700/10">نموذج توضيحي</span>
        </div>
      </div>
      <p className="flex items-start gap-2 text-sm leading-relaxed text-muted-foreground" data-demo-card-disclaimer>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        {FAMILY_CARD_DISCLAIMER}
      </p>
    </div>
  );
}

function UseList({ icon: Icon, iconClass, title, items, field }: { icon: LucideIcon; iconClass: string; title: string; items: readonly string[]; field: string }) {
  return (
    <div className="rounded-xl border border-stroke-subtle bg-surface-2 p-4" data-card-uses={field}>
      <h3 className="mb-2 text-sm font-bold text-foreground">{title}</h3>
      <ul className="flex flex-col gap-2 text-sm leading-relaxed text-muted-foreground">
        {items.map((item) => (
          <li key={item} className="flex items-start gap-2">
            <Icon className={cn("mt-1 size-4 shrink-0", iconClass)} aria-hidden />
            {item}
          </li>
        ))}
      </ul>
    </div>
  );
}

export function DigitalFamilyCardSection() {
  return (
    <section id="digital-family-card" aria-labelledby="digital-family-card-title" className={cn(LANDING_SECTION, "bg-surface-1")}>
      {/* In RTL the first column is on the right: the text, then the card on the left. */}
      <div className={cn(LANDING_SHELL, "grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)] lg:gap-12")}>
        <div className="flex min-w-0 flex-col gap-6">
          <header>
            <span className="mb-2 block text-[13px] font-bold tracking-wider text-brand-700">{DIGITAL_CARD.eyebrow}</span>
            <h2 id="digital-family-card-title" className="text-2xl leading-snug font-bold text-balance text-foreground sm:text-3xl">
              {DIGITAL_CARD.title}
            </h2>
            <p className="mt-2 text-lg font-semibold text-pretty text-brand-800">{DIGITAL_CARD.supporting}</p>
            <p className="mt-3 max-w-2xl text-base leading-relaxed text-pretty text-muted-foreground">{DIGITAL_CARD.description}</p>
          </header>

          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {DIGITAL_CARD.features.map((feature) => (
              <li key={feature.key} className="flex gap-3 rounded-xl border border-stroke-subtle bg-canvas p-4" data-card-feature={feature.key}>
                <IconBox icon={FEATURE_VISUALS[feature.key].icon} tone={FEATURE_VISUALS[feature.key].tone} />
                <div className="min-w-0">
                  <h3 className="mb-1 text-sm font-bold text-foreground">{feature.title}</h3>
                  <p className="text-sm leading-relaxed text-muted-foreground">{feature.body}</p>
                </div>
              </li>
            ))}
          </ul>

          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <UseList field="current" icon={Check} iconClass="text-success" title="كيف تُستخدم البطاقة" items={DIGITAL_CARD.currentUses} />
            <UseList field="limits" icon={CircleSlash} iconClass="text-danger" title="ما لا تعنيه البطاقة" items={DIGITAL_CARD.limits} />
          </div>

          <div className="flex flex-col items-stretch gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <Link href="/family/login" className={cn("inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-brand-700 px-7 text-base font-semibold text-white shadow-e2 transition-colors hover:bg-brand-800", focusRing)}>
              الدخول إلى بوابة الأسرة
              <ArrowLeft className="size-5" aria-hidden />
            </Link>
            <a href="#preview" className={cn("inline-flex h-12 items-center justify-center rounded-xl border border-stroke-subtle bg-surface-1 px-6 text-base font-semibold text-foreground transition-colors hover:bg-surface-hover", focusRing)}>
              استعرض معاينة المنصة
            </a>
          </div>
        </div>

        <DemoCard />
      </div>
    </section>
  );
}
