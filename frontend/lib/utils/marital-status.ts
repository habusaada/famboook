// The single canonical presentation of persons.marital_status. UNKNOWN is
// the default and is never inferred to be SINGLE.
export type MaritalStatus = "SINGLE" | "MARRIED" | "DIVORCED" | "WIDOWED" | "UNKNOWN";

export const MARITAL_STATUSES: MaritalStatus[] = ["SINGLE", "MARRIED", "DIVORCED", "WIDOWED", "UNKNOWN"];

export const maritalStatusLabels: Record<MaritalStatus, string> = {
  SINGLE: "أعزب/عزباء",
  MARRIED: "متزوج/ة",
  DIVORCED: "مطلق/ة",
  WIDOWED: "أرمل/ة",
  UNKNOWN: "غير معروف",
};
