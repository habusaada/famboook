import type { HealthRecord, HealthRecordType } from "@/lib/types/api/health";

export const healthRecordTypeLabels: Record<HealthRecordType, string> = {
  DISABILITY: "إعاقة",
  CHRONIC_DISEASE: "مرض مزمن",
  PREGNANCY: "حمل",
  BREASTFEEDING: "رضاعة طبيعية",
};

// Pregnancy/breastfeeding: FEMALE members only (enforced by the API too).
export function isMaternalType(type: HealthRecordType | undefined): boolean {
  return type === "PREGNANCY" || type === "BREASTFEEDING";
}

/** What the record is about: disability type or disease name. */
export function healthRecordSubject(record: HealthRecord): string | null {
  if (record.type === "DISABILITY") return record.disability_type?.name ?? null;
  if (record.type === "CHRONIC_DISEASE") return record.condition_name;
  return null;
}
