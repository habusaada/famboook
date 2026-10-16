import { z } from "zod";
import type { FamilyProfile } from "@/lib/api/family-household";

// The household head's RESIDENCE_UPDATE form (PWA-6.1): a correction of the
// CURRENT residence. The eight text/status fields are the backend handler's
// set (ResidenceUpdateHandler::FIELDS); payload v2 (FB-GEO-01 ADR §8.5) adds
// the two optional structured references (LOCATION_FIELDS). Laravel is the
// validation authority — these rules only spare a round trip.

type Residence = NonNullable<FamilyProfile["residence"]>;

const SHORT_MAX = 255;
const ADDRESS_MAX = 1000;

const shortText = z.string().max(SHORT_MAX, "النص طويل جدًا.");

export const residenceUpdateSchema = z
  .object({
    governorate: shortText,
    city: shortText,
    area: shortText,
    neighborhood: shortText,
    addressText: z.string().max(ADDRESS_MAX, "النص طويل جدًا."),
    originalResidenceText: shortText,
    // "" = not collected; only allowed while the registry has no status.
    displacementStatus: z.enum(["", "DISPLACED", "NOT_DISPLACED"]),
    displacementLocationText: shortText,
    // v2: Gaza location UUIDs from GazaLocationSelector (source "family"); null = not recorded.
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
  const address = residence.current_address;
  return {
    governorate: address.governorate ?? "",
    city: address.city ?? "",
    area: address.area ?? "",
    neighborhood: address.neighborhood ?? "",
    addressText: address.address_text ?? "",
    originalResidenceText: residence.original_residence_text ?? "",
    displacementStatus: residence.displacement_status ?? "",
    displacementLocationText: residence.displacement_location_text ?? "",
    originalLocationId: residence.original_location?.id ?? null,
    currentLocationId: residence.current_location?.id ?? null,
    reason: "",
  };
}

const orNull = (value: string) => (value.trim() === "" ? null : value.trim());

/** The `data` of POST /api/v1/family/change-requests: the whole proposed residence. */
export function toResidenceUpdateData(values: ResidenceUpdateValues) {
  const displaced = values.displacementStatus === "DISPLACED";
  return {
    governorate: orNull(values.governorate),
    city: orNull(values.city),
    area: orNull(values.area),
    neighborhood: orNull(values.neighborhood),
    address_text: orNull(values.addressText),
    original_residence_text: orNull(values.originalResidenceText),
    displacement_status: values.displacementStatus === "" ? null : values.displacementStatus,
    displacement_location_text: displaced ? orNull(values.displacementLocationText) : null,
    // v2: always the whole proposal; the server keeps only what changed, and
    // an unchanged (even inactive) saved reference is accepted as is.
    original_location_id: values.originalLocationId,
    current_location_id: values.currentLocationId,
  };
}
export type ResidenceUpdateData = ReturnType<typeof toResidenceUpdateData>;

/** Did the family change anything? (The server refuses an unchanged proposal too.) */
export function residenceUpdateChanges(residence: Residence, values: ResidenceUpdateValues): boolean {
  const before = toResidenceUpdateData(residenceUpdateFormValues(residence));
  const after = toResidenceUpdateData(values);
  return (Object.keys(after) as (keyof ResidenceUpdateData)[]).some((key) => before[key] !== after[key]);
}

/** Server field (data key) → form field. */
export const RESIDENCE_UPDATE_FIELDS: Record<string, keyof ResidenceUpdateValues> = {
  governorate: "governorate",
  city: "city",
  area: "area",
  neighborhood: "neighborhood",
  address_text: "addressText",
  original_residence_text: "originalResidenceText",
  displacement_status: "displacementStatus",
  displacement_location_text: "displacementLocationText",
  original_location_id: "originalLocationId",
  current_location_id: "currentLocationId",
  reason: "reason",
};
