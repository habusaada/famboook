import { z } from "zod";
import type { FamilyProfile } from "@/lib/api/family-household";

// The household head's RESIDENCE_UPDATE form (PWA-6.1), payload v3
// (FB-GEO-02): the two structured Gaza references, the optional detailed
// address and the displacement status — nothing else. The identity-card
// residence (original_residence_text) and the historical free-text address
// fields are read-only here. Only the CHANGED keys are sent: an omitted key
// stays as it is, an explicit null clears a reference. Laravel is the
// validation authority — these rules only spare a round trip.

type Residence = NonNullable<FamilyProfile["residence"]>;

const ADDRESS_MAX = 1000;

export const residenceUpdateSchema = z.object({
  addressText: z.string().max(ADDRESS_MAX, "النص طويل جدًا."),
  // "" = not collected; only allowed while the registry has no status.
  displacementStatus: z.enum(["", "DISPLACED", "NOT_DISPLACED"]),
  // Gaza location UUIDs from GazaLocationSelector (source "family"); null = not recorded.
  originalLocationId: z.string().nullable(),
  currentLocationId: z.string().nullable(),
  reason: z.string().max(2000, "النص طويل جدًا."),
});
export type ResidenceUpdateValues = z.infer<typeof residenceUpdateSchema>;

/** The schema for a residence whose displacement status is already known: it cannot be cleared. */
export function residenceUpdateSchemaFor(residence: Residence) {
  if (residence.displacement_status === null) return residenceUpdateSchema;
  return residenceUpdateSchema.refine((v) => v.displacementStatus !== "", {
    path: ["displacementStatus"],
    message: "اختر حالة النزوح.",
  });
}

export function residenceUpdateFormValues(residence: Residence): ResidenceUpdateValues {
  return {
    addressText: residence.current_address.address_text ?? "",
    displacementStatus: residence.displacement_status ?? "",
    originalLocationId: residence.original_location?.id ?? null,
    currentLocationId: residence.current_location?.id ?? null,
    reason: "",
  };
}

const orNull = (value: string) => (value.trim() === "" ? null : value.trim());

function proposal(values: ResidenceUpdateValues) {
  return {
    original_location_id: values.originalLocationId,
    current_location_id: values.currentLocationId,
    address_text: orNull(values.addressText),
    displacement_status: values.displacementStatus === "" ? null : values.displacementStatus,
  };
}
type Proposal = ReturnType<typeof proposal>;
export type ResidenceUpdateData = Partial<Proposal>;

/**
 * The `data` of POST /api/v1/family/change-requests: only the v3 keys whose
 * value differs from the saved residence — never a null for an untouched
 * field, so nothing is cleared by accident.
 */
export function toResidenceUpdateData(residence: Residence, values: ResidenceUpdateValues): ResidenceUpdateData {
  const before = proposal(residenceUpdateFormValues(residence));
  const after = proposal(values);
  const data: ResidenceUpdateData = {};
  for (const key of Object.keys(after) as (keyof Proposal)[]) {
    if (before[key] !== after[key]) Object.assign(data, { [key]: after[key] });
  }
  return data;
}

/** Did the family change anything? (The server refuses an unchanged proposal too.) */
export function residenceUpdateChanges(residence: Residence, values: ResidenceUpdateValues): boolean {
  return Object.keys(toResidenceUpdateData(residence, values)).length > 0;
}

/** Server field (data key) → form field. */
export const RESIDENCE_UPDATE_FIELDS: Record<string, keyof ResidenceUpdateValues> = {
  address_text: "addressText",
  displacement_status: "displacementStatus",
  original_location_id: "originalLocationId",
  current_location_id: "currentLocationId",
  reason: "reason",
};
