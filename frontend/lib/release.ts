// The public release identity of Famboook (FB-LEGAL-01B): the ONE source of
// the release designation shown to users. It is deliberately independent of
// package.json's internal version, which is not a public release number.

export const RELEASE = {
  product: "Famboook",
  version: "V1.0",
  label: "Initial Production Release",
  /** The production launch date (ISO 8601, calendar date). */
  launchDate: "2026-10-01",
} as const;

/** "Famboook V1.0" — the designation shown in the footer and on /about. */
export const RELEASE_DESIGNATION = `${RELEASE.product} ${RELEASE.version}`;
