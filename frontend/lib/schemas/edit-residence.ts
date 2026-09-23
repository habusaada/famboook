import { z } from "zod";
import type {
  FamilyDetail,
  UpdateFamilyResidencePayload,
} from "@/lib/types/api/family";

// UX validation only — Laravel's UpdateFamilyResidenceRequest is
// authoritative. Each dialog sends only its own fields (partial PATCH).

type Residence = NonNullable<FamilyDetail["residence"]>;

// ---------- النزوح ----------

// UNKNOWN is the form's name for a NULL status (not collected). It is
// sent back as null, so a legacy record is never converted to
// NOT_DISPLACED just by opening and saving the dialog.
export const editDisplacementSchema = z.object({
  originalResidenceText: z.string().max(255, "النص طويل جدًا").optional(),
  displacementStatus: z.enum(["DISPLACED", "NOT_DISPLACED", "UNKNOWN"]),
  displacementLocationText: z.string().max(255, "النص طويل جدًا").optional(),
});

export type EditDisplacementValues = z.infer<typeof editDisplacementSchema>;

export function displacementFormValues(residence: Residence): EditDisplacementValues {
  return {
    originalResidenceText: residence.original_residence_text ?? "",
    displacementStatus: residence.displacement_status ?? "UNKNOWN",
    displacementLocationText: residence.displacement_location_text ?? "",
  };
}

export function toDisplacementPayload(
  values: EditDisplacementValues
): UpdateFamilyResidencePayload {
  const status =
    values.displacementStatus === "UNKNOWN" ? null : values.displacementStatus;

  return {
    original_residence_text: values.originalResidenceText || null,
    displacement_status: status,
    displacement_location_text:
      status === "DISPLACED" ? values.displacementLocationText || null : null,
  };
}

export const displacementApiFieldToFormField: Record<
  string,
  keyof EditDisplacementValues
> = {
  original_residence_text: "originalResidenceText",
  displacement_status: "displacementStatus",
  displacement_location_text: "displacementLocationText",
};

// ---------- السكن الحالي ----------

export const editCurrentResidenceSchema = z.object({
  governorate: z.string().trim().min(1, "المحافظة مطلوبة").max(255, "النص طويل جدًا"),
  city: z.string().trim().min(1, "المدينة مطلوبة").max(255, "النص طويل جدًا"),
  area: z.string().max(255, "النص طويل جدًا").optional(),
  neighborhood: z.string().max(255, "النص طويل جدًا").optional(),
  addressText: z.string().optional(),
});

export type EditCurrentResidenceValues = z.infer<typeof editCurrentResidenceSchema>;

export function currentResidenceFormValues(
  residence: Residence
): EditCurrentResidenceValues {
  return {
    governorate: residence.governorate,
    city: residence.city,
    area: residence.area ?? "",
    neighborhood: residence.neighborhood ?? "",
    addressText: residence.address_text ?? "",
  };
}

export function toCurrentResidencePayload(
  values: EditCurrentResidenceValues
): UpdateFamilyResidencePayload {
  return {
    governorate: values.governorate,
    city: values.city,
    area: values.area || null,
    neighborhood: values.neighborhood || null,
    address_text: values.addressText || null,
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
};
