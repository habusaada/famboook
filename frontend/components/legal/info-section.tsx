// One titled card of an informational legal page (/about, /contact —
// FB-LEGAL-01B/01D). Server-rendered, no client state; the existing card
// tokens of the public pages.

export const infoCard = "rounded-2xl border border-border bg-surface-1 p-5";

export function InfoSection({ id, title, children }: { id: string; title: string; children: React.ReactNode }) {
  return (
    <section className={infoCard} aria-labelledby={`info-${id}`} data-info-section={id}>
      <h2 id={`info-${id}`} className="text-base font-semibold text-foreground">
        {title}
      </h2>
      <div className="mt-2 text-sm leading-relaxed text-foreground">{children}</div>
    </section>
  );
}
