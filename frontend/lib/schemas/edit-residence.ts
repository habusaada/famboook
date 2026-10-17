import { z } from "zod";
import type {
  FamilyDetail,
  UpdateFamilyResidencePayload,
} from "@/lib/types/api/family";

// UX validation only — Laravel's UpdateFamilyResidenceRequest is
// authoritative. Each dialog sends only its own fields (partial PATCH).

type Residence = NonNullable<FamilyDetail["residence"]>;

// FB-GEO-01 Stage 4B: a structured location reference is sent ONLY when it
// differs from the saved one — compared with the saved residence, not with
// the control's state. Omitted = unchanged (an inactive saved value stays);
// a new UUID = a new selection; null = an explicit, confirmed clear.
function locationChange(
  key: "original_location_id" | "current_location_id",
  value: string | null,
  residence: Residence
): UpdateFamilyResidencePayload {
  return value === (residence[key] ?? null) ? {} : { [key]: value };
}

// ---------- النزوح ----------

// UNKNOWN is the form's name for a NULL status (not collected). It is
// sent back as null, so a legacy record is never converted to
// NOT_DISPLACED just by opening and saving the dialog.
export const editDisplacementSchema = z.object({
  originalResidenceText: z.string().max(255, "النص طويل جدًا").optional(),
  displacementStatus: z.enum(["DISPLACED", "NOT_DISPLACED", "UNKNOWN"]),
  displacementLocationText: z.string().max(255, "النص طويل جدًا").optional(),
  // Residence before displacement (original_location_id).
  originalLocationId: z.string().nullable(),
});

export type EditDisplacementValues = z.infer<typeof editDisplacementSchema>;

export function displacementFormValues(residence: Residence): EditDisplacementValues {
  return {
    originalResidenceText: residence.original_residence_text ?? "",
    displacementStatus: residence.displacement_status ?? "UNKNOWN",
    displacementLocationText: residence.displacement_location_text ?? "",
    originalLocationId: residence.original_location_id ?? null,
  };
}

export function toDisplacementPayload(
  values: EditDisplacementValues,
  residence: Residence
): UpdateFamilyResidencePayload {
  const status =
    values.displacementStatus === "UNKNOWN" ? null : values.displacementStatus;

  return {
    original_residence_text: values.originalResidenceText || null,
    displacement_status: status,
    // FB-GEO-02: only a displaced family's location is sent; otherwise the key
    // is omitted and the stored text is kept as historical data.
    ...(status === "DISPLACED" ? { displacement_location_text: values.displacementLocationText || null } : {}),
    ...locationChange("original_location_id", values.originalLocationId, residence),
  };
}

export const displacementApiFieldToFormField: Record<
  string,
  keyof EditDisplacementValues
> = {
  original_residence_text: "originalResidenceText",
  displacement_status: "displacementStatus",
  displacement_location_text: "displacementLocationText",
  original_location_id: "originalLocationId",
};

// ---------- السكن الحالي ----------

export const editCurrentResidenceSchema = z.object({
  // Optional: empty = not recorded (NULL), never a guessed value.
  governorate: z.string().max(255, "النص طويل جدًا").optional(),
  city: z.string().max(255, "النص طويل جدًا").optional(),
  area: z.string().max(255, "النص طويل جدًا").optional(),
  neighborhood: z.string().max(255, "النص طويل جدًا").optional(),
  addressText: z.string().optional(),
  // Where the family lives now — the displacement location when DISPLACED
  // (current_location_id).
  currentLocationId: z.string().nullable(),
});

export type EditCurrentResidenceValues = z.infer<typeof editCurrentResidenceSchema>;

export function currentResidenceFormValues(
  residence: Residence
): EditCurrentResidenceValues {
  return {
    governorate: residence.governorate ?? "",
    city: residence.city ?? "",
    area: residence.area ?? "",
    neighborhood: residence.neighborhood ?? "",
    addressText: residence.address_text ?? "",
    currentLocationId: residence.current_location_id ?? null,
  };
}

export function toCurrentResidencePayload(
  values: EditCurrentResidenceValues,
  residence: Residence
): UpdateFamilyResidencePayload {
  return {
    governorate: values.governorate?.trim() || null,
    city: values.city?.trim() || null,
    area: values.area || null,
    neighborhood: values.neighborhood || null,
    address_text: values.addressText || null,
    ...locationChange("current_location_id", values.currentLocationId, residence),
  };
}

export const currentResidenceApiFieldToFormField: Record<
  string,
  keyof EditCurrentResidenceValues
> = {
  governorate: "governorate",
  city: "city",
  area: "area",
  neighborhood: "neighborhood",
  address_text: "addressText",
  current_location_id: "currentLocationId",
};
