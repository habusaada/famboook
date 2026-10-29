import { describe, expect, it } from "vitest";
import { completedYears, formatCivilDate, gazaToday, parseCivilDate, recordAge } from "@/lib/utils/civil-date";

// FB-CIVIL-02E — the Civil Registry Lookup age and date display.

const d = (year: number, month: number, day: number) => ({ year, month, day });

describe("parseCivilDate", () => {
  it("reads the source ISO text as a calendar date", () => {
    expect(parseCivilDate("1990-01-15T00:00:00")).toEqual(d(1990, 1, 15));
    expect(parseCivilDate("1990-01-15")).toEqual(d(1990, 1, 15));
    expect(parseCivilDate("2000-02-29T00:00:00")).toEqual(d(2000, 2, 29));
  });

  it("rejects missing and impossible dates", () => {
    for (const raw of [null, undefined, "", "   ", "15/01/1990", "1990-13-01", "2023-02-29", "1990-04-31", "abcd-01-01", "19900115"]) {
      expect(parseCivilDate(raw)).toBeNull();
    }
  });
});

describe("completedYears", () => {
  it("counts a birthday only once it is reached", () => {
    expect(completedYears(d(1990, 6, 15), d(2026, 6, 14))).toBe(35);
    expect(completedYears(d(1990, 6, 15), d(2026, 6, 15))).toBe(36);
    expect(completedYears(d(1990, 6, 15), d(2026, 12, 31))).toBe(36);
    expect(completedYears(d(1990, 12, 31), d(2026, 1, 1))).toBe(35);
  });

  it("completes a 29 February birthday on 1 March in non-leap years, on the day in leap years", () => {
    expect(completedYears(d(2000, 2, 29), d(2025, 2, 28))).toBe(24);
    expect(completedYears(d(2000, 2, 29), d(2025, 3, 1))).toBe(25);
    expect(completedYears(d(2000, 2, 29), d(2028, 2, 28))).toBe(27);
    expect(completedYears(d(2000, 2, 29), d(2028, 2, 29))).toBe(28);
  });

  it("is 0 on the day of birth and null for a future date", () => {
    expect(completedYears(d(2026, 10, 29), d(2026, 10, 29))).toBe(0);
    expect(completedYears(d(2026, 10, 30), d(2026, 10, 29))).toBeNull();
    expect(completedYears(d(2027, 1, 1), d(2026, 10, 29))).toBeNull();
  });
});

describe("gazaToday", () => {
  it("uses the calendar date in Asia/Gaza, not UTC", () => {
    // 22:30 UTC on 28 Oct 2026 is already 29 Oct in Gaza (UTC+2 after the DST end).
    expect(gazaToday(new Date("2026-10-28T22:30:00Z"))).toEqual(d(2026, 10, 29));
    expect(gazaToday(new Date("2026-10-28T21:30:00Z"))).toEqual(d(2026, 10, 28));
  });
});

describe("recordAge", () => {
  const now = new Date("2026-10-29T10:00:00Z");

  it("counts a living person's completed years up to today, before and after the birthday", () => {
    expect(recordAge("1990-11-15T00:00:00", null, now)).toEqual({ kind: "LIVING", years: 35, deathKnown: true });
    expect(recordAge("1990-10-29T00:00:00", null, now)).toEqual({ kind: "LIVING", years: 36, deathKnown: true });
    expect(recordAge("1990-10-01T00:00:00", "", now)).toEqual({ kind: "LIVING", years: 36, deathKnown: true });
  });

  it("turns a year older at Gaza midnight", () => {
    expect(recordAge("1990-10-29T00:00:00", null, new Date("2026-10-28T21:30:00Z"))).toMatchObject({ years: 35 });
    expect(recordAge("1990-10-29T00:00:00", null, new Date("2026-10-28T22:30:00Z"))).toMatchObject({ years: 36 });
  });

  it("flags an age computed without the death date when the level does not return it", () => {
    expect(recordAge("1990-11-15T00:00:00", undefined, now)).toEqual({ kind: "LIVING", years: 35, deathKnown: false });
  });

  it("stops at the date of death for a deceased person", () => {
    expect(recordAge("1950-03-10T00:00:00", "2020-03-09T00:00:00", now)).toEqual({ kind: "AT_DEATH", years: 69 });
    expect(recordAge("1950-03-10T00:00:00", "2020-03-10T00:00:00", now)).toEqual({ kind: "AT_DEATH", years: 70 });
    // A death today is valid.
    expect(recordAge("1950-03-10T00:00:00", "2026-10-29", now)).toEqual({ kind: "AT_DEATH", years: 76 });
  });

  it("applies the 29 February convention at death too", () => {
    expect(recordAge("2000-02-29T00:00:00", "2025-02-28T00:00:00", now)).toEqual({ kind: "AT_DEATH", years: 24 });
    expect(recordAge("2000-02-29T00:00:00", "2025-03-01T00:00:00", now)).toEqual({ kind: "AT_DEATH", years: 25 });
  });

  it("never substitutes today for a deceased person with an unusable death date", () => {
    for (const death of ["غير معروف", "2020-02-30", "2027-01-01T00:00:00", "1949-12-31T00:00:00"]) {
      expect(recordAge("1950-03-10T00:00:00", death, now)).toEqual({ kind: "UNKNOWN", deceased: true });
    }
  });

  it("is unknown for a missing, invalid or future birth date", () => {
    for (const birth of [null, undefined, "", "not a date", "2030-01-01T00:00:00"]) {
      expect(recordAge(birth, null, now)).toEqual({ kind: "UNKNOWN", deceased: false });
    }
    expect(recordAge(null, "2020-01-01", now)).toEqual({ kind: "UNKNOWN", deceased: true });
  });
});

describe("formatCivilDate", () => {
  it("shows the date only, without a time, and never shifts the day", () => {
    const shown = formatCivilDate("1990-01-15T00:00:00");
    expect(shown).not.toBeNull();
    expect(shown).not.toMatch(/00:00|T/);
    expect(shown).toBe(new Intl.DateTimeFormat("ar", { timeZone: "UTC", day: "numeric", month: "long", year: "numeric" }).format(new Date(Date.UTC(1990, 0, 15))));
  });

  it("keeps a non-ISO source value as given and returns null when empty", () => {
    expect(formatCivilDate("15/01/1990")).toBe("15/01/1990");
    expect(formatCivilDate("")).toBeNull();
    expect(formatCivilDate(null)).toBeNull();
  });
});
