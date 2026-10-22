// The legal operator of Famboook (FB-LEGAL-01, decision D1 — APPROVED): PCDCD
// operates the platform and is the data management authority. Famboook stays
// the platform name and brand; nothing here asserts who owns its intellectual
// property.
//
// Contact channels (D2, FB-LEGAL-01D): only a VERIFIED ACTIVE address may be
// displayed. Today that is the institutional address alone. The Famboook
// support address is PLANNED and not yet active: it is deliberately absent and
// is added only on launch day, after the mailbox is verified
// (docs/deployment/FB-LEGAL-01D-LAUNCH-DAY-CONTACT-CHECKLIST.md). The media
// address is never shown on Famboook pages. No telephone or postal address is
// approved.

export const OPERATOR = {
  nameAr: "المركز الفلسطيني للبيانات والتنمية المجتمعية",
  abbreviation: "PCDCD",
  /** The approved role wording. */
  roleAr: "تشغيل وإدارة البيانات",
  /** PCDCD's public page, already linked by the footer since FB-BRAND-01. Not a contact channel. */
  publicPageUrl: "https://www.facebook.com/pcdcd.gaza",
  /** The official institutional email — verified ACTIVE (2026-10-22); the current channel for inquiries and support. */
  contactEmail: "info@pcdcd.org",
} as const;

/** «المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)» */
export const OPERATOR_DISPLAY_NAME = `${OPERATOR.nameAr} (${OPERATOR.abbreviation})`;
