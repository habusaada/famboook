import type { Gender } from "@/lib/types/api/family";
import type { RelationshipType } from "@/lib/types/api/reference";

// Presentation only (docs/02 §15 "V1 Operational Baseline"): SPOUSE is the
// single canonical code, shown as زوج/زوجة from the person's gender.
// Legacy memberships with no recorded relationship are never guessed.
export function relationshipLabel(
  relationship: RelationshipType | null,
  gender: Gender | null | undefined
): string {
  if (!relationship) return "غير محدد";

  if (relationship.code === "SPOUSE") {
    if (gender === "MALE") return "زوج";
    if (gender === "FEMALE") return "زوجة";
  }

  return relationship.name;
}
