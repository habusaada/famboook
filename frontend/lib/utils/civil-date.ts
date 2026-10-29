// FB-CIVIL-02E — dates of the Civil Registry Lookup. The source gives dates
// as ISO text ("1990-01-15T00:00:00"). They are calendar dates, not instants:
// they are read as year / month / day and never shifted by a time zone. The
// stored `age` column is never shown; the age is computed here (recordAge),
// as of today's date in Asia/Gaza or at death, and nothing is written back.

export type CivilDate = { year: number; month: number; day: number };

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/;

/** The calendar date of a source value, or null when it is missing or not a real date. */
export function parseCivilDate(raw: string | null | undefined): CivilDate | null {
  const match = raw ? ISO_DATE.exec(raw.trim()) : null;
  if (!match) return null;
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  // Rejects 2023-02-29, 1990-13-01 and the like (Date.UTC would roll them over).
  const check = new Date(Date.UTC(year, month - 1, day));
  if (check.getUTCFullYear() !== year || check.getUTCMonth() !== month - 1 || check.getUTCDate() !== day) return null;
  return { year, month, day };
}

/** Today's calendar date in Asia/Gaza. */
export function gazaToday(now: Date = new Date()): CivilDate {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Gaza", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(now);
  const part = (type: string) => Number(parts.find((p) => p.type === type)?.value);
  return { year: part("year"), month: part("month"), day: part("day") };
}

/**
 * Completed years between two calendar dates; null when the birth date is
 * after `on`. A birthday counts once its month and day are reached, so a
 * 29 February birthday is completed on 1 March in a non-leap year.
 */
export function completedYears(birth: CivilDate, on: CivilDate): number | null {
  const reached = on.month > birth.month || (on.month === birth.month && on.day >= birth.day);
  const years = on.year - birth.year - (reached ? 0 : 1);
  return years < 0 ? null : years;
}

/**
 * The age shown for a record (FB-CIVIL-02E):
 *   LIVING    completed years from birth to today (Gaza)
 *   AT_DEATH  completed years from birth to the date of death — it never grows after death
 *   UNKNOWN   no reliable age: missing / invalid / future birth date, or a deceased person whose
 *             death date is invalid, in the future or before the birth date (today is never
 *             substituted, and the stored age is never used)
 *
 * Death is known only from the source `deathdate` (a non-empty value). `deathDate` is
 * undefined when the response does not include that field at all (the STANDARD and
 * EXTENDED_CONTROLLED levels): the age is then computed as for a living person and
 * `deathKnown` is false, so the screen can say so. `presonstate` is never interpreted.
 */
export type CivilAge =
  | { kind: "LIVING"; years: number; deathKnown: boolean }
  | { kind: "AT_DEATH"; years: number }
  | { kind: "UNKNOWN"; deceased: boolean };

export function recordAge(rawBirthDate: string | null | undefined, rawDeathDate: string | null | undefined, now: Date = new Date()): CivilAge {
  const deceased = typeof rawDeathDate === "string" && rawDeathDate.trim() !== "";
  const birth = parseCivilDate(rawBirthDate);
  const today = gazaToday(now);
  if (!deceased) {
    const years = birth ? completedYears(birth, today) : null;
    return years === null ? { kind: "UNKNOWN", deceased: false } : { kind: "LIVING", years, deathKnown: rawDeathDate !== undefined };
  }
  const death = parseCivilDate(rawDeathDate);
  // A death date in the future is invalid; before the birth date, completedYears returns null.
  const years = birth && death && completedYears(death, today) !== null ? completedYears(birth, death) : null;
  return years === null ? { kind: "UNKNOWN", deceased: true } : { kind: "AT_DEATH", years };
}

/**
 * A source date for display, date only (no time). A value that is not an ISO
 * date is shown as the source gives it — never reinterpreted.
 */
export function formatCivilDate(raw: string | null | undefined): string | null {
  if (!raw || raw.trim() === "") return null;
  const date = parseCivilDate(raw);
  if (!date) return raw;
  return new Intl.DateTimeFormat("ar", { timeZone: "UTC", day: "numeric", month: "long", year: "numeric" }).format(
    new Date(Date.UTC(date.year, date.month - 1, date.day)),
  );
}
