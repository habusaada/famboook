import { FileClock } from "lucide-react";
import type { LegalPage, PublishedPolicy } from "@/lib/legal/pages";
import { formatDateLong } from "@/lib/utils/date";

// The body of a legal page (FB-LEGAL-01B). Server-rendered, no client state.

const card = "rounded-2xl border border-border bg-surface-1 p-5";

/**
 * A page awaiting legal approval: the title and a neutral notice only — no
 * policy text, no version, no effective date and no acceptance control.
 */
function PendingNotice({ page }: { page: LegalPage }) {
  return (
    <article className="flex flex-col gap-4" aria-labelledby="legal-title" data-legal-page={page.id} data-legal-status="PENDING_APPROVAL">
      <h1 id="legal-title" className="text-2xl leading-snug font-bold text-foreground">
        {page.title}
      </h1>
      <section className={`${card} flex items-start gap-3`} data-legal-pending>
        <FileClock className="mt-0.5 size-5 shrink-0 text-muted-foreground" aria-hidden />
        <p className="text-sm leading-relaxed text-foreground">هذه الصفحة قيد الإعداد والاعتماد لدى الجهة المشغّلة، ولم يُنشر محتواها بعد.</p>
      </section>
    </article>
  );
}

/** An APPROVED policy: its sections, version and effective date. */
function Policy({ page }: { page: PublishedPolicy }) {
  return (
    <article className="flex flex-col gap-4" aria-labelledby="legal-title" data-legal-page={page.id} data-legal-status="PUBLISHED">
      <header className="flex flex-col gap-1.5">
        <h1 id="legal-title" className="text-2xl leading-snug font-bold text-foreground">
          {page.title}
        </h1>
        <p className="text-[13px] text-muted-foreground" data-legal-effective>
          تاريخ السريان: {formatDateLong(page.effectiveDate)} · الإصدار: <bdi dir="ltr">{page.version}</bdi>
        </p>
      </header>
      {page.sections.map((section, i) => (
        <section key={i} className={`${card} flex flex-col gap-2`} aria-labelledby={`legal-section-${i}`}>
          <h2 id={`legal-section-${i}`} className="text-base font-semibold text-foreground">
            {section.heading}
          </h2>
          {section.paragraphs.map((paragraph, j) => (
            <p key={j} className="text-sm leading-relaxed text-foreground">
              {paragraph}
            </p>
          ))}
        </section>
      ))}
    </article>
  );
}

/**
 * A legal page by its registry entry: pending → the notice; a published
 * policy → its approved content. Informational pages (about) render their
 * own verified content and do not use this view.
 */
export function LegalPageView({ page }: { page: LegalPage }) {
  if (page.status === "PENDING_APPROVAL") return <PendingNotice page={page} />;
  if (page.kind === "policy") return <Policy page={page} />;
  throw new Error(`The informational page "${page.id}" renders its own content.`);
}
