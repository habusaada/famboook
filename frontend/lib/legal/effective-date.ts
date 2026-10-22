// The effective date of the published legal policies (FB-LEGAL publication).
//
// The Executive Director approved version 1.0 with the ACTUAL Production
// publication date as the effective date, identical for every published
// policy. That date cannot be known in advance, so it is supplied at build
// time in NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE (YYYY-MM-DD) by the operator on
// the publication day. Never a default, a commit date or a placeholder:
//
//   unset / empty   no policy is published (the pages keep the neutral
//                   "not published yet" notice)
//   invalid         the module throws, and a production build fails
//                   (next.config.ts), so nothing is published silently
//
// next.config.ts additionally refuses a date in the future or before the
// publication authorization (assertPublishableEffectiveDate). The check runs
// on the server only, never against a browser clock. Imported by
// next.config.ts: keep this module free of path aliases and dependencies.

/** The policy version approved for the first publication (FB-LEGAL-01N: MAJOR.MINOR). */
export const POLICY_VERSION = "1.0";

/** The Executive Director's publication authorization: no effective date may precede it. */
export const EARLIEST_EFFECTIVE_DATE = "2026-10-22";

/** The time zone in which "today" is the publication day. */
export const PUBLICATION_TIME_ZONE = "Asia/Gaza";

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** True for a real calendar date written YYYY-MM-DD. */
export function isCalendarDate(value: string): boolean {
  const match = ISO_DATE.exec(value);
  if (!match) return false;
  const [, y, m, d] = match.map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}

/**
 * The configured effective date, or null when none is set (nothing is
 * published). Throws on a malformed value so a typo can never publish.
 */
export function legalEffectiveDate(raw: string | undefined): string | null {
  const value = (raw ?? "").trim();
  if (value === "") return null;
  if (!isCalendarDate(value)) {
    throw new Error(`NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE must be a real date written YYYY-MM-DD (got "${value}").`);
  }
  return value;
}

/** The configured date for a policy being published: a real date, or an error (never null). */
export function requiredEffectiveDate(raw: string | undefined): string {
  const date = legalEffectiveDate(raw);
  if (date === null) throw new Error("NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE is blank; a published policy needs the actual publication date.");
  return date;
}

/** Today's calendar date (YYYY-MM-DD) in the publication time zone. */
export function publicationToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: PUBLICATION_TIME_ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).format(now);
}

/**
 * Build-time guard (server only): a configured date must be a real date, not
 * before the publication authorization and not in the future. The first
 * publication sets it to the publication day; later rebuilds keep that same
 * date, which stays valid.
 */
export function assertPublishableEffectiveDate(raw: string | undefined, now: Date = new Date()): string | null {
  const date = legalEffectiveDate(raw);
  if (date === null) return null;
  if (date < EARLIEST_EFFECTIVE_DATE) {
    throw new Error(`NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE ${date} is before the publication authorization (${EARLIEST_EFFECTIVE_DATE}).`);
  }
  const today = publicationToday(now);
  if (date > today) {
    throw new Error(`NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE ${date} is in the future (today in ${PUBLICATION_TIME_ZONE}: ${today}); it must be the actual publication date.`);
  }
  return date;
}
