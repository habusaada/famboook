import { describe, expect, it } from "vitest";
import { OMIT_REASON_CODES, applyErrorLabel, planReasonLabel } from "@/components/administration/import-wizard/labels";

// CO-01D.5 Phase B (docs/04 DB-ADR-061): the Import Wizard names the new
// National ID blocking reasons and the Apply errors that enforce the same
// rule — never the generic "unclassified" fallback.

const UNCLASSIFIED_REASON = planReasonLabel("__NO_SUCH_CODE__");
const UNCLASSIFIED_ERROR = applyErrorLabel("__NO_SUCH_CODE__");

describe("National ID blocking reasons", () => {
  it.each(["HEAD_WITHOUT_NATIONAL_ID", "HEAD_NATIONAL_ID_INVALID", "SPOUSE_NATIONAL_ID_INVALID"])("labels %s", (code) => {
    expect(planReasonLabel(code)).not.toBe(UNCLASSIFIED_REASON);
  });

  it("says a head without a National ID blocks Apply", () => {
    expect(planReasonLabel("HEAD_WITHOUT_NATIONAL_ID")).toContain("يمنع التطبيق");
  });

  it("keeps a spouse without a National ID an intentional, non-blocking omission", () => {
    expect(OMIT_REASON_CODES.has("SPOUSE_WITHOUT_NATIONAL_ID")).toBe(true);
    expect(OMIT_REASON_CODES.has("HEAD_WITHOUT_NATIONAL_ID")).toBe(false);
    expect(OMIT_REASON_CODES.has("SPOUSE_NATIONAL_ID_INVALID")).toBe(false);
  });
});

describe("National ID Apply errors", () => {
  it.each(["PERSON_VALUES_INVALID", "NATIONAL_ID_TAKEN"])("labels %s", (code) => {
    expect(applyErrorLabel(code)).not.toBe(UNCLASSIFIED_ERROR);
  });
});
