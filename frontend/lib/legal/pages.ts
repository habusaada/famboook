import type { Metadata } from "next";

// The public legal and information pages (FB-LEGAL-01B) and their
// PUBLICATION STATUS — the one place that decides what may be shown and
// linked. Decisions: docs/decisions/FB-LEGAL-01-DECISIONS.md.
//
//   PENDING_APPROVAL  the route exists but shows only a neutral "not
//                     published yet" notice: no policy text, no version, no
//                     effective date, no acceptance control; never linked.
//   PUBLISHED         linked from LegalLinks. An informational page renders
//                     its own verified content; a policy renders its approved
//                     sections with its version and effective date.
//
// Approved policy text is inserted HERE (status PUBLISHED, kind "policy")
// once PCDCD approves it — no route or component changes are needed. Never
// add draft or unapproved text: drafts belong in internal documentation.

export type LegalPageId = "about" | "privacy" | "terms" | "data-protection" | "contact";

export type LegalSection = { heading: string; paragraphs: readonly string[] };

type Base = {
  id: LegalPageId;
  href: `/${string}`;
  title: string;
  /** One sentence for the page metadata. */
  description: string;
};

export type PendingLegalPage = Base & { status: "PENDING_APPROVAL" };

export type PublishedInformationPage = Base & { status: "PUBLISHED"; kind: "information" };

export type PublishedPolicy = Base & {
  status: "PUBLISHED";
  kind: "policy";
  /** The approved version identifier, e.g. "privacy-2026-11-01". */
  version: string;
  /** The approved effective date (ISO 8601 calendar date). */
  effectiveDate: string;
  sections: readonly LegalSection[];
};

export type LegalPage = PendingLegalPage | PublishedInformationPage | PublishedPolicy;

/** In footer order. */
export const LEGAL_PAGES: readonly LegalPage[] = [
  {
    id: "about",
    href: "/about",
    title: "عن فامبوك",
    description: "تعريف بمنصة Famboook والجهة المشغّلة لها.",
    status: "PUBLISHED",
    kind: "information",
  },
  {
    id: "privacy",
    href: "/privacy",
    title: "سياسة الخصوصية",
    description: "سياسة الخصوصية لمنصة Famboook.",
    status: "PENDING_APPROVAL",
  },
  {
    id: "terms",
    href: "/terms",
    title: "شروط الاستخدام",
    description: "شروط استخدام منصة Famboook.",
    status: "PENDING_APPROVAL",
  },
  {
    id: "data-protection",
    href: "/data-protection",
    title: "إشعار حماية البيانات",
    description: "إشعار حماية البيانات لمنصة Famboook.",
    status: "PENDING_APPROVAL",
  },
  {
    // FB-LEGAL-01D: verified ACTIVE channel only (lib/legal/operator.ts); the page renders its own content.
    id: "contact",
    href: "/contact",
    title: "التواصل",
    description: "قناة التواصل مع الجهة المشغّلة لمنصة Famboook.",
    status: "PUBLISHED",
    kind: "information",
  },
];

export function legalPage(id: LegalPageId): LegalPage {
  const page = LEGAL_PAGES.find((p) => p.id === id);
  if (!page) throw new Error(`Unknown legal page: ${id}`);
  return page;
}

/** The pages that may be linked: published only. */
export function publishedLegalPages(): readonly LegalPage[] {
  return LEGAL_PAGES.filter((p) => p.status === "PUBLISHED");
}

/**
 * Page metadata. Indexing is OFF for every legal page until decision D9
 * (indexing and publication) is taken; a pending page stays unindexed even
 * then.
 */
export function legalMetadata(id: LegalPageId): Metadata {
  const page = legalPage(id);
  return {
    title: `${page.title} — Famboook`,
    description: page.description,
    robots: { index: false, follow: false },
  };
}
