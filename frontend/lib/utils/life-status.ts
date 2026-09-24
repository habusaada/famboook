import type { LifeStatus } from "@/lib/types/api/person";

// docs/02-DATA-DICTIONARY.md §10 "life_status".
export function lifeStatusLabel(status: LifeStatus | null | undefined): string {
  if (status === "ALIVE") return "حي";
  if (status === "DECEASED") return "متوفى";
  return "غير معروف";
}
