// The registry National ID rule on the client (CO-01D.5), mirroring Laravel's
// App\Support\RegistryNationalId (CO-01D.4, docs/04 DB-ADR-061): every NEW
// Person has exactly nine ASCII digits, never nine identical digits (a
// placeholder), stored in that canonical form. The server stays the
// authority — this only spares a round trip and sends the canonical value.
//
// Normalization is the same as FamilyInput::clean: Arabic-Indic and Persian
// digits become ASCII digits; ONLY whitespace, the bidirectional marks an RTL
// keyboard inserts, invisible formatting characters (ZWSP/ZWNJ/ZWJ,
// directional embeddings and isolates, BOM) and the separators - . / _ are
// removed. Letters and other symbols are kept, so they fail the format check:
// arbitrary input is never stripped into a valid identifier.

/** RegistryNationalId::MESSAGE — the server's own wording. */
export const NATIONAL_ID_MESSAGE = "رقم الهوية مطلوب ويجب أن يتكون من 9 أرقام.";

/** FamilyInput::MAX_LENGTH: longer raw input is never a valid identifier. */
export const NATIONAL_ID_MAX_INPUT = 32;

const ARABIC_DIGITS: Record<string, string> = {
  "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
  "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
};

// \s covers the Unicode space separators, tab and line breaks; then LRM, RLM,
// ALM; ZWSP, ZWNJ, ZWJ; LRE…RLO; LRI…PDI; BOM; and - . / _
const REMOVABLE = /[\s‎‏؜​-‍‪-‮⁦-⁩﻿\-./_]/g;

/** Arabic-Indic / Persian digits → ASCII; removable characters dropped (as FamilyInput::clean). */
export function cleanDigits(value: string): string {
  return value.replace(/[٠-٩۰-۹]/g, (d) => ARABIC_DIGITS[d] ?? d).replace(REMOVABLE, "");
}

/** Nine identical digits (000000000, 999999999 …): never a real National ID. */
export function isPlaceholderNationalId(digits: string): boolean {
  return /^([0-9])\1{8}$/.test(digits);
}

/**
 * The canonical nine digits, or null when the value is missing or invalid —
 * the same answer as RegistryNationalId::canonical().
 */
export function canonicalNationalId(value: string | null | undefined): string | null {
  if (typeof value !== "string" || value.length > NATIONAL_ID_MAX_INPUT) return null;
  const digits = cleanDigits(value);
  if (!/^[0-9]{9}$/.test(digits) || isPlaceholderNationalId(digits)) return null;
  return digits;
}

/** Whether the value is a valid registry National ID (after normalization). */
export function isValidNationalId(value: string | null | undefined): boolean {
  return canonicalNationalId(value) !== null;
}
