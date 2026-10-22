import type { Metadata } from "next";
import { OPERATOR, OPERATOR_DISPLAY_NAME } from "@/lib/legal/operator";
import { legalMetadata, legalPage } from "@/lib/legal/pages";
import { RELEASE, RELEASE_DESIGNATION } from "@/lib/release";
import { formatDateLong } from "@/lib/utils/date";

export const metadata: Metadata = legalMetadata("about");

const card = "rounded-2xl border border-border bg-surface-1 p-5";

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm font-medium text-foreground sm:text-end">{children}</dd>
    </div>
  );
}

/**
 * «عن فامبوك» (FB-LEGAL-01B): verified product facts and the approved operator
 * identity (D1) only — no legal claim, contact detail or guarantee.
 */
export default function AboutPage() {
  const page = legalPage("about");

  return (
    <article className="flex flex-col gap-4" aria-labelledby="legal-title" data-legal-page="about" data-legal-status="PUBLISHED">
      <h1 id="legal-title" className="text-2xl leading-snug font-bold text-foreground">
        {page.title}
      </h1>

      <section className={card} aria-labelledby="about-product">
        <h2 id="about-product" className="text-base font-semibold text-foreground">
          المنصة
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-foreground">
          <bdi>{RELEASE.product}</bdi> نظام لسجل العائلات وإدارة الحالات. يعمل فيه فريق العمل المخوّل على سجل الأسر وأفرادها، ويطّلع رب الأسرة على بيانات أسرته عبر بوابة الأسرة.
        </p>
      </section>

      <section className={card} aria-labelledby="about-release">
        <h2 id="about-release" className="text-base font-semibold text-foreground">
          الإصدار
        </h2>
        <dl className="mt-1 divide-y divide-border/70">
          <Row label="الإصدار" field="release">
            <bdi dir="ltr" data-release-designation>
              {RELEASE_DESIGNATION}
            </bdi>
          </Row>
          <Row label="وصف الإصدار" field="release-label">
            <bdi dir="ltr">{RELEASE.label}</bdi>
          </Row>
          <Row label="بدء التشغيل الفعلي" field="launch">
            <time dateTime={RELEASE.launchDate}>{formatDateLong(RELEASE.launchDate)}</time>
          </Row>
        </dl>
      </section>

      <section className={card} aria-labelledby="about-operator">
        <h2 id="about-operator" className="text-base font-semibold text-foreground">
          الجهة المشغّلة
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-foreground" data-operator>
          {OPERATOR.roleAr}: {OPERATOR_DISPLAY_NAME}
        </p>
      </section>
    </article>
  );
}
