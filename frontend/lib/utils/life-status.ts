import type { LifeStatus, LifeStatusVerificationMethod } from "@/lib/types/api/person";

// docs/02-DATA-DICTIONARY.md §10 "life_status".
export function lifeStatusLabel(status: LifeStatus | null | undefined): string {
  if (status === "ALIVE") return "حي";
  if (status === "DECEASED") return "متوفى";
  return "غير معروف";
}

// How Staff verified a Person alive (ConfirmPersonAliveRequest).
export const lifeStatusVerificationLabels: Record<LifeStatusVerificationMethod, string> = {
  IN_PERSON: "تحقق حضوري",
  STAFF_CALLBACK: "اتصال هاتفي",
  AUTHORIZED_RECORD_REVIEW: "مراجعة السجل",
};
