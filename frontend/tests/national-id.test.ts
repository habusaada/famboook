import { describe, expect, it } from "vitest";
import { NATIONAL_ID_MESSAGE, canonicalNationalId, cleanDigits, isPlaceholderNationalId, isValidNationalId } from "@/lib/utils/national-id";
import { cleanDigits as familyCleanDigits } from "@/lib/schemas/family-add-member";
import { addFamilyMemberSchema, toAddFamilyMemberPayload } from "@/lib/schemas/add-family-member";
import { familyRegistrationSchema, toRegisterFamilyPayload, type FamilyRegistrationValues } from "@/lib/schemas/family-registration";

// CO-01D.5: the client mirror of Laravel's RegistryNationalId. The cases are
// the same as the backend's (NationalIdAuditTest::storedValues and
// NationalIdDuplicateTest) so the two answer alike. Synthetic values only.

describe("canonicalNationalId", () => {
  it.each([
    ["401234567", "401234567"],
    [" 401234567 ", "401234567"],
    ["401-234 567", "401234567"],
    ["٤٠١٢٣٤٥٦٧", "401234567"],
    ["۴۰۱۲۳۴۵۶۷", "401234567"],
    ["401.234/567", "401234567"],
    ["401_234_567", "401234567"],
    // Bidi marks and invisible characters a copy-paste carries.
    ["‏401234567‎", "401234567"],
    ["401​234⁦567⁩﻿", "401234567"],
    ["٩١٣-٥٧٠ ٢٤٩", "913570249"],
  ])("normalizes %j to %s", (input, expected) => {
    expect(canonicalNationalId(input)).toBe(expected);
    expect(isValidNationalId(input)).toBe(true);
  });

  it.each([
    [null],
    [undefined],
    [""],
    ["   "],
    ["40123456"],
    ["4012345678"],
    ["A40123456"],
    ["P1234567"],
    ["SYN-400333444"],
    ["000000000"],
    ["999999999"],
    ["111111111"],
    ["٠٠٠٠٠٠٠٠٠"],
    // Longer than FamilyInput::MAX_LENGTH before cleaning: never valid.
    ["401234567" + " ".repeat(30)],
  ])("refuses %j", (input) => {
    expect(canonicalNationalId(input)).toBeNull();
    expect(isValidNationalId(input)).toBe(false);
  });

  it("keeps letters and symbols so they fail, never stripping input into an ID", () => {
    expect(cleanDigits("A01234567")).toBe("A01234567");
    expect(cleanDigits("40123456#7")).toBe("40123456#7");
    expect(canonicalNationalId("40123456#7")).toBeNull();
  });

  it("recognizes placeholders only as nine identical digits", () => {
    expect(isPlaceholderNationalId("000000000")).toBe(true);
    expect(isPlaceholderNationalId("111111110")).toBe(false);
  });

  it("uses the server's own message", () => {
    expect(NATIONAL_ID_MESSAGE).toBe("رقم الهوية مطلوب ويجب أن يتكون من 9 أرقام.");
  });

  it("is the same normalizer the Family Portal form uses", () => {
    expect(familyCleanDigits).toBe(cleanDigits);
  });
});

describe("Staff schemas", () => {
  const registration = (headNationalId: string): FamilyRegistrationValues => ({
    registrationDate: "2026-10-01",
    registrationSource: "MANUAL_ENTRY",
    clanCode: "AL_BREEM",
    headFullName: "رب أسرة تجريبي",
    headNationalId,
    headGender: "MALE",
    headMaritalStatus: "UNKNOWN",
  });

  it("registration requires a valid National ID and sends it canonically", () => {
    for (const invalid of ["", "   ", "40123456", "000000000", "SYN-1"]) {
      const result = familyRegistrationSchema.safeParse(registration(invalid));
      expect(result.success).toBe(false);
      expect(result.error?.issues.find((i) => i.path[0] === "headNationalId")?.message).toBe(NATIONAL_ID_MESSAGE);
    }
    const parsed = familyRegistrationSchema.parse(registration("٤٠١-٢٣٤ ٥٦٧"));
    expect(toRegisterFamilyPayload(parsed).household_head.national_id).toBe("401234567");
  });

  it("add-member requires a valid National ID and sends it canonically", () => {
    const member = (nationalId: string) => ({
      relationshipTypeId: 3,
      fullName: "مولود جديد",
      nationalId,
      gender: "MALE" as const,
      maritalStatus: "UNKNOWN" as const,
    });
    for (const invalid of ["", "12345678", "999999999"]) {
      const result = addFamilyMemberSchema.safeParse(member(invalid));
      expect(result.success).toBe(false);
      expect(result.error?.issues.find((i) => i.path[0] === "nationalId")?.message).toBe(NATIONAL_ID_MESSAGE);
    }
    expect(toAddFamilyMemberPayload(addFamilyMemberSchema.parse(member(" 401 234 567 "))).national_id).toBe("401234567");
  });
});
