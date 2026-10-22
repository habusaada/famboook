// The legal operator of Famboook (FB-LEGAL-01, decision D1 — APPROVED): PCDCD
// operates the platform and is the data management authority. Famboook stays
// the platform name and brand; nothing here asserts who owns its intellectual
// property. Official contact details (D2) are NOT approved yet and are
// deliberately absent: never add an email, telephone or address here until
// PCDCD confirms them.

export const OPERATOR = {
  nameAr: "المركز الفلسطيني للبيانات والتنمية المجتمعية",
  abbreviation: "PCDCD",
  /** The approved role wording. */
  roleAr: "تشغيل وإدارة البيانات",
  /** PCDCD's public page, already linked by the footer since FB-BRAND-01. Not a contact channel. */
  publicPageUrl: "https://www.facebook.com/pcdcd.gaza",
} as const;

/** «المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)» */
export const OPERATOR_DISPLAY_NAME = `${OPERATOR.nameAr} (${OPERATOR.abbreviation})`;
