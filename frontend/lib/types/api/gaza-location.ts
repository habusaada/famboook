// Types mirroring backend/app/Http/Resources/GazaLocationResource.php
// (FB-GEO-01 Stage 2B, details `is_active` since Stage 3.2). Ids are UUIDs.

export type GazaLocationLevel = "GOVERNORATE" | "CITY" | "AREA" | "NEIGHBORHOOD" | "LANDMARK";

/** An item of /governorates and /{uuid}/children: ACTIVE locations only, no is_active. */
export interface GazaLocation {
  id: string;
  level: GazaLocationLevel;
  /** Display value (edge-trimmed by the API). */
  name: string;
  parent_id: string | null;
  /** Whether the location has ACTIVE children. */
  has_children: boolean;
  /** Landmark type name for a LANDMARK, otherwise null. Staff responses only: absent from the Family mirrors (API-D4). */
  landmark_type?: string | null;
}

/** An ancestor in a details path (governorate first, parent last). */
export interface GazaLocationPathItem {
  id: string;
  level: GazaLocationLevel;
  name: string;
  is_active: boolean;
}

/** GET /gaza-locations/{uuid}: any status, with is_active and the ancestor path. */
export interface GazaLocationDetails extends GazaLocation {
  is_active: boolean;
  path: GazaLocationPathItem[];
}

/**
 * A residence's location reference as summarised in the Staff family
 * response (backend GazaLocationSummary, Stage 3.2): any status, with its
 * ancestor path. Never source codes or review flags.
 */
export interface GazaLocationSummary {
  id: string;
  level: GazaLocationLevel;
  name: string;
  is_active: boolean;
  path: GazaLocationPathItem[];
}
