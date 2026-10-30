import Link from "next/link";
import {
  ArrowLeft,
  BadgeCheck,
  ChartColumn,
  Check,
  CircleCheck,
  Compass,
  FileCheck,
  FolderOpen,
  Home,
  Landmark,
  LockKeyhole,
  Mail,
  Megaphone,
  Network,
  ShieldCheck,
  Smartphone,
  UsersRound,
  type LucideIcon,
} from "lucide-react";
import { BrandLogo } from "@/components/shared/brand-logo";
import { IconBox, type Tone } from "@/components/shared/icon-box";
import { SiteFooter } from "@/components/shared/site-footer";
import { cn } from "@/lib/utils";
import { ABOUT, ABOUT_PILLARS, CAPABILITIES, CONTACT_EMAIL, EXPERIENCES, FAQ, GOVERNANCE, GOVERNANCE_INTRO, HERO, HERO_PILLARS, LANDING_NAV, LANDING_SECTION, LANDING_SHELL, ONBOARDING_STEPS, OPERATOR_LINE, OPERATOR_ROLE } from "./landing-content";
import { DigitalFamilyCardSection } from "./digital-family-card-section";
import { LandingFaq, LandingHeader } from "./landing-interactive";
import { PlatformPreview } from "./platform-preview";

// FB-WEB-02B — the public page (/), following the approved Stitch V2.1
// section order and layout with the application's own tokens, logo and font.
// A server component: only the header, the FAQ and the preview run on the client.

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";
const shell = LANDING_SHELL;
const section = LANDING_SECTION;

const PILLAR_VISUALS: Record<string, { icon: LucideIcon; tone: Tone }> = {
  records: { icon: FolderOpen, tone: "brand" },
  council: { icon: Landmark, tone: "brand" },
  pwa: { icon: Smartphone, tone: "success" },
  operator: { icon: ShieldCheck, tone: "info" },
  coordination: { icon: UsersRound, tone: "success" },
  governance: { icon: LockKeyhole, tone: "info" },
  branches: { icon: Network, tone: "brand" },
  requests: { icon: FileCheck, tone: "brand" },
  notifications: { icon: Megaphone, tone: "info" },
  reports: { icon: ChartColumn, tone: "brand" },
  head: { icon: Home, tone: "brand" },
  coordinator: { icon: Network, tone: "success" },
  staff: { icon: ShieldCheck, tone: "info" },
  roles: { icon: LockKeyhole, tone: "brand" },
  review: { icon: FileCheck, tone: "success" },
  privacy: { icon: ShieldCheck, tone: "brand" },
};

function SectionIntro({ eyebrow, title, description }: { eyebrow: string; title: string; description?: string }) {
  return (
    <div className="mx-auto mb-8 max-w-2xl text-center lg:mb-12">
      <span className="mb-2 block text-[13px] font-bold tracking-wider text-brand-700">{eyebrow}</span>
      <h2 className="text-2xl leading-snug font-bold text-balance text-foreground sm:text-3xl">{title}</h2>
      {description && <p className="mt-2 text-base leading-relaxed text-pretty text-muted-foreground">{description}</p>}
    </div>
  );
}

function Hero() {
  return (
    <section id="hero" className="relative w-full scroll-mt-16 overflow-hidden border-b border-stroke-subtle/50 bg-gradient-to-b from-brand-50/60 to-canvas py-10 sm:py-14 lg:scroll-mt-20 lg:py-20">
      <div aria-hidden className="pointer-events-none absolute top-12 right-1/4 size-96 rounded-full bg-brand-500/10 blur-3xl" />
      <div aria-hidden className="pointer-events-none absolute bottom-10 left-12 size-80 rounded-full bg-success/10 blur-3xl" />
      <div className={cn(shell, "relative z-10 flex flex-col items-center text-center")}>
        <p className="mb-5 inline-flex items-center gap-2 rounded-full border border-stroke-subtle bg-surface-1 px-4 py-1.5 text-[13px] leading-relaxed font-semibold text-brand-900 shadow-e1 lg:text-sm">
          <span aria-hidden className="size-2 shrink-0 rounded-full bg-success" />
          {OPERATOR_LINE}
        </p>
        <h1 className="mb-4 max-w-4xl text-[1.875rem] leading-[1.3] font-bold tracking-tight text-balance text-foreground sm:text-5xl sm:leading-[1.25] lg:text-[3.5rem]">
          {HERO.title}
        </h1>
        <p className="mx-auto mb-7 max-w-2xl text-base leading-[1.85] text-pretty text-muted-foreground sm:text-lg">
          {HERO.description}
        </p>
        <div className="mb-10 flex w-full flex-col items-stretch justify-center gap-3 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
          <Link href="/login" className={cn("inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-brand-700 px-7 text-base font-semibold text-white shadow-e2 transition-colors hover:bg-brand-800", focusRing)}>
            {HERO.primaryCta}
            <ArrowLeft className="size-5" aria-hidden />
          </Link>
          <Link href="/family/login" className={cn("inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-brand-700/30 bg-surface-1 px-6 text-base font-semibold text-brand-700 transition-colors hover:bg-brand-50", focusRing)}>
            <Home className="size-5" aria-hidden />
            {HERO.secondaryCta}
          </Link>
          <a href="#capabilities" className={cn("inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-stroke-subtle bg-surface-1 px-6 text-base font-semibold text-foreground transition-colors hover:bg-surface-hover", focusRing)}>
            <Compass className="size-5 text-brand-600" aria-hidden />
            {HERO.thirdCta}
          </a>
        </div>
        <ul className="grid w-full max-w-4xl grid-cols-1 gap-3 md:grid-cols-3">
          {HERO_PILLARS.map((pillar) => (
            <li key={pillar.key} className="flex items-center gap-3 rounded-xl border border-stroke-subtle bg-surface-1/80 p-3.5 text-start shadow-e1">
              <IconBox icon={PILLAR_VISUALS[pillar.key].icon} tone={PILLAR_VISUALS[pillar.key].tone} />
              <div className="min-w-0">
                <div className="text-[13px] text-muted-foreground">{pillar.eyebrow}</div>
                <div className="text-sm font-bold text-foreground">{pillar.title}</div>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

function Preview() {
  return (
    <section id="preview" className={cn(section, "bg-surface-2/60")}>
      <div className={shell}>
        <SectionIntro
          eyebrow="معاينة إمكانات فامبوك"
          title="بيئة تفاعلية منظمة لإدارة البيانات والتنسيق"
          description="مكوّنات من واجهة فامبوك الفعلية ببيانات توضيحية اصطناعية، لتوضيح تنظيم الفروع والأسر وتجربة المنسقين وأرباب الأسر."
        />
        <PlatformPreview />
      </div>
    </section>
  );
}

function About() {
  return (
    <section id="about" className={cn(section, "bg-surface-1")}>
      <div className={shell}>
        <div className="mb-8 flex flex-col items-start justify-between gap-6 lg:mb-12 lg:flex-row lg:items-end lg:gap-8">
          <div className="max-w-2xl">
            <span className="mb-3 inline-flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-1 text-[13px] font-semibold text-brand-700">
              <Landmark className="size-4" aria-hidden />
              إدارة مؤسسية
            </span>
            <h2 className="text-2xl leading-snug font-bold text-balance text-foreground sm:text-4xl sm:leading-tight">{ABOUT.title}</h2>
            <p className="mt-4 text-base leading-relaxed text-muted-foreground">
              {ABOUT.body} {OPERATOR_LINE}.
            </p>
          </div>
          <div className="max-w-md rounded-2xl border border-stroke-subtle bg-surface-2 p-5">
            <div className="mb-2 flex items-center gap-3">
              <ShieldCheck className="size-6 text-success" aria-hidden />
              <span className="text-sm font-bold text-foreground">{ABOUT.asideTitle}</span>
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground">{ABOUT.aside}</p>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
          {ABOUT_PILLARS.map((pillar) => (
            <article key={pillar.key} className="flex flex-col justify-between rounded-2xl border border-stroke-subtle bg-canvas p-6 shadow-e1">
              <div>
                <IconBox className="mb-5" size="lg" icon={PILLAR_VISUALS[pillar.key].icon} tone={PILLAR_VISUALS[pillar.key].tone} />
                <h3 className="mb-2 text-lg font-bold text-foreground">{pillar.title}</h3>
                <p className="text-sm leading-relaxed text-muted-foreground">{pillar.body}</p>
              </div>
              <p className="mt-6 border-t border-stroke-subtle/60 pt-4 text-[13px] font-bold text-brand-700">{pillar.footer}</p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

function Capabilities() {
  return (
    <section id="capabilities" className={cn(section, "bg-surface-2/40")}>
      <div className={shell}>
        <SectionIntro eyebrow="إمكانات المنصة" title="قدرات عملية لتنظيم العائلات والعشائر" description="مصممة لاحتياجات مجالس العائلات والمنسقين وأرباب الأسر، وتُتاح الخدمات على مراحل." />
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
          {CAPABILITIES.map((item) => (
            <article key={item.key} className="flex flex-col justify-between rounded-2xl border border-stroke-subtle bg-surface-1 p-6 shadow-e1">
              <div>
                <div className="mb-4 flex items-center justify-between gap-2">
                  <IconBox size="lg" icon={PILLAR_VISUALS[item.key].icon} tone={PILLAR_VISUALS[item.key].tone} />
                  <span className={cn("rounded-full px-2.5 py-0.5 text-[13px] font-semibold", item.badge === "خاضع للتفعيل" ? "bg-surface-2 text-muted-foreground" : "bg-success-soft text-success")}>{item.badge}</span>
                </div>
                <h3 className="mb-2 text-lg font-bold text-foreground">{item.title}</h3>
                <p className="text-sm leading-relaxed text-muted-foreground">{item.body}</p>
              </div>
              <p className="mt-5 flex items-center gap-1.5 border-t border-stroke-subtle/50 pt-3 text-[13px] text-muted-foreground">
                <CircleCheck className="size-4 shrink-0 text-success" aria-hidden />
                {item.note}
              </p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

function HowItWorks() {
  return (
    <section id="how-it-works" className={cn(section, "bg-surface-1")}>
      <div className={shell}>
        <SectionIntro eyebrow="لمن صُممت فامبوك وكيف تعمل" title="أدوار واضحة تبدأ من مجلس العائلة" description="ينضم إلى فامبوك العائلة أو العشيرة عبر مجلسها، ولكل طرف دور وصلاحيات محددة." />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {EXPERIENCES.map((item) => (
            <article key={item.key} className="flex flex-col justify-between rounded-2xl border border-stroke-subtle bg-surface-2 p-5 sm:p-7">
              <div>
                <IconBox className="mb-5" size="lg" icon={PILLAR_VISUALS[item.key].icon} tone={PILLAR_VISUALS[item.key].tone} />
                <h3 className="mb-2 text-xl font-bold text-foreground">
                  <bdi>{item.number}</bdi> — {item.title}
                </h3>
                <p className="mb-4 text-[13px] font-bold text-brand-700">{item.subtitle}</p>
                <p className="mb-6 text-sm leading-relaxed text-muted-foreground">{item.body}</p>
                <ul className="flex flex-col gap-2.5 text-sm text-muted-foreground">
                  {item.points.map((point) => (
                    <li key={point} className="flex items-start gap-2">
                      <Check className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
                      {point}
                    </li>
                  ))}
                </ul>
              </div>
              <p className="mt-6 border-t border-stroke-subtle pt-4 text-[13px] text-muted-foreground">{item.footer}</p>
            </article>
          ))}
        </div>
        <p className="mt-6 flex items-start gap-3 rounded-2xl border border-stroke-subtle bg-canvas p-4 text-sm leading-relaxed text-muted-foreground sm:p-5" data-operator-role>
          <ShieldCheck className="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden />
          {OPERATOR_ROLE}
        </p>
        <div className="mt-6 rounded-2xl border border-stroke-subtle bg-canvas p-6" data-onboarding>
          <h3 className="mb-4 text-base font-bold text-foreground">كيف تنضم العائلة أو العشيرة؟</h3>
          <ol className="grid grid-cols-1 gap-4 text-sm leading-relaxed text-muted-foreground md:grid-cols-2 lg:grid-cols-4">
            {ONBOARDING_STEPS.map((step, index) => (
              <li key={step} className="flex items-start gap-3">
                <span aria-hidden className="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[13px] font-bold text-brand-700 tabular-nums">
                  {(index + 1).toLocaleString("ar")}
                </span>
                {step}
              </li>
            ))}
          </ol>
        </div>
      </div>
    </section>
  );
}

function Governance() {
  return (
    <section id="governance" className={cn(section, "bg-surface-2/40")}>
      <div className={shell}>
        <div className="rounded-3xl border border-stroke-subtle bg-surface-1 p-6 shadow-e1 sm:p-8 lg:p-12">
          <div className="mb-8 max-w-2xl border-b border-stroke-subtle pb-6 lg:mb-10 lg:pb-8">
            <span className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-success-soft px-3 py-1 text-[13px] font-bold text-success">
              <BadgeCheck className="size-4" aria-hidden />
              حوكمة مسؤولة
            </span>
            <h2 className="text-2xl leading-snug font-bold text-balance text-foreground sm:text-3xl">خصوصية البيانات وحوكمة الوصول</h2>
            <p className="mt-2 text-base leading-relaxed text-pretty text-muted-foreground">
              {GOVERNANCE_INTRO}
            </p>
          </div>
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            {GOVERNANCE.map((item) => {
              const visual = PILLAR_VISUALS[item.key] ?? PILLAR_VISUALS.operator;
              return (
                <article key={item.key} className="rounded-xl border border-stroke-subtle bg-canvas p-4">
                  <IconBox className="mb-3" icon={visual.icon} tone={visual.tone} />
                  <h3 className="mb-1 text-sm font-bold text-foreground">{item.title}</h3>
                  <p className="text-sm leading-relaxed text-muted-foreground">{item.body}</p>
                </article>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
}

function Faq() {
  return (
    <section id="faq" className={cn(section, "bg-surface-1")}>
      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-12">
        <SectionIntro eyebrow="الأسئلة الشائعة" title="إجابات واضحة حول منصة فامبوك" description="الفئات المستهدفة، وانضمام العائلات، وإدارة البيانات، والجهة المشغلة." />
        <LandingFaq items={FAQ} />
      </div>
    </section>
  );
}

function FinalCta() {
  return (
    <section className="w-full bg-surface-1 py-12 lg:py-20">
      <div className={shell}>
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-900 via-brand-700 to-brand-600 p-6 text-white shadow-e3 sm:p-12 lg:p-16">
          <div className="relative z-10 flex max-w-3xl flex-col items-start">
            <h2 className="mb-4 text-2xl leading-snug font-bold tracking-tight text-balance sm:text-4xl lg:text-5xl lg:leading-tight">عائلة منظمة.. فروع مترابطة.. تنسيق أوضح</h2>
            <p className="mb-8 max-w-2xl text-base leading-relaxed text-pretty text-white/90">اكتشف كيف تساعد فامبوك مجالس العائلات والعشائر على تنظيم بيانات فروعها وأسرها وأفرادها عبر منظومة موحدة. للاستفسار عن انضمام عائلتك أو عشيرتك، راسلنا عبر <bdi dir="ltr" className="font-semibold text-white">{CONTACT_EMAIL}</bdi>.</p>
            <div className="flex w-full flex-col items-stretch gap-3 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
              <Link href="/login" className="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-white px-8 font-bold text-brand-900 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white text-base">
                الدخول إلى فامبوك
                <ArrowLeft className="size-5" aria-hidden />
              </Link>
              <a href="#capabilities" className="inline-flex h-12 items-center justify-center rounded-xl border border-white/20 bg-white/10 px-6 font-semibold text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white text-base">
                تعرّف على المنصة
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Footer() {
  const link = cn("rounded-sm hover:text-brand-700", focusRing);
  return (
    <footer className="w-full border-t border-stroke-subtle bg-surface-2" data-landing-footer>
      <div className={cn(shell, "py-12 lg:py-16")}>
        <div className="mb-10 grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-4">
          <div className="flex flex-col gap-3">
            <BrandLogo className="h-10" />
            <p className="text-sm leading-relaxed text-muted-foreground">منصة رقمية لتنظيم العائلات والعشائر.</p>
          </div>
          <nav aria-label="روابط المنصة" className="flex flex-col gap-2.5">
            <h2 className="mb-1 text-sm font-bold text-foreground">روابط المنصة</h2>
            <div className="flex flex-col gap-2 text-sm text-muted-foreground">
              {LANDING_NAV.slice(0, 4).map((item) => (
                <a key={item.href} href={item.href} className={cn("w-fit", link)}>
                  {item.label}
                </a>
              ))}
              <a href="#digital-family-card" className={cn("w-fit", link)}>بطاقة الأسرة الرقمية</a>
            </div>
          </nav>
          <nav aria-label="الخصوصية والدخول" className="flex flex-col gap-2.5">
            <h2 className="mb-1 text-sm font-bold text-foreground">الخصوصية والدخول</h2>
            <div className="flex flex-col gap-2 text-sm text-muted-foreground">
              <a href="#governance" className={cn("w-fit", link)}>الخصوصية وحوكمة الوصول</a>
              <a href="#faq" className={cn("w-fit", link)}>الأسئلة الشائعة</a>
              <Link href="/login" className={cn("w-fit", link)}>دخول الطاقم</Link>
              <Link href="/family/login" className={cn("w-fit", link)}>دخول رب الأسرة</Link>
            </div>
          </nav>
          <div className="flex flex-col gap-2.5">
            <h2 className="mb-1 text-sm font-bold text-foreground">الجهة المشغلة والتواصل</h2>
            <p className="text-sm leading-relaxed text-muted-foreground">{OPERATOR_LINE}</p>
            <a href={`mailto:${CONTACT_EMAIL}`} className={cn("inline-flex w-fit items-center gap-2 text-sm text-muted-foreground", link)} data-contact-email>
              <Mail className="size-4 text-brand-600" aria-hidden />
              <bdi dir="ltr">{CONTACT_EMAIL}</bdi>
            </a>
          </div>
        </div>
        <SiteFooter className="border-t border-stroke-subtle pt-6" />
      </div>
    </footer>
  );
}

export function LandingPage() {
  return (
    <div className="min-h-svh overflow-x-clip bg-canvas text-foreground" data-landing-page>
      <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-[60] focus:rounded-lg focus:bg-surface-1 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-brand-700 focus:shadow-e2">
        تخطَّ إلى المحتوى
      </a>
      <LandingHeader />
      <main id="main-content" className="w-full pt-16 lg:pt-20">
        <Hero />
        <Preview />
        <DigitalFamilyCardSection />
        <About />
        <Capabilities />
        <HowItWorks />
        <Governance />
        <Faq />
        <FinalCta />
      </main>
      <Footer />
    </div>
  );
}
