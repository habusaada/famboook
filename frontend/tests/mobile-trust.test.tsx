import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { PersonProfileView } from "@/components/people/person-profile-view";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { MobileTrustRecord, MobileTrustState, PersonMobileTrust } from "@/lib/types/api/mobile-trust";
import type { PersonDetail } from "@/lib/types/api/person";
import { renderWithClient } from "./helpers";

// FU-15: the Staff «توثيق رقم الجوال» card on the person profile. Synthetic
// data only — no real number, name or fingerprint.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/people/PER-000123" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const PERSON_PATH = "/api/v1/people/PER-000123";
const TRUST_PATH = "/api/v1/people/PER-000123/mobile-trust";
const REVOKE_PATH = "/api/v1/people/PER-000123/mobile-trust/revoke";
const MASK = "********67";
// A fingerprint-shaped value: must never reach the page even if sent.
const FINGERPRINT = "f".repeat(64);

const ALL = ["person.view", "person-mobile-trust.view", "person-mobile-trust.grant", "person-mobile-trust.revoke"];

function person(): PersonDetail {
  return {
    person_code: "PER-000123",
    full_name: "فرد تجريبي",
    gender: "MALE",
    marital_status: "MARRIED",
    birth_date: "1980-01-15",
    mobile: null,
    alternate_mobile: null,
    alternate_mobile_owner_relation: null,
    life_status: "ALIVE",
    is_active: true,
    family_membership: {
      family_code: "FAM-000123",
      is_household_head: true,
      relationship_type: { id: 1, code: "HEAD", name: "رب الأسرة" },
      started_at: null,
    },
  } as PersonDetail;
}

function row(overrides: Partial<MobileTrustRecord> = {}): MobileTrustRecord {
  return {
    id: "00000000-0000-4000-8000-000000000001",
    status: "TRUSTED",
    mobile_masked: MASK,
    verification_method: "IN_PERSON",
    verified_at: "2026-10-01T09:00:00+03:00",
    verified_by: "مسؤول تجريبي",
    stale_at: null,
    revoked_at: null,
    revoked_by: null,
    revoke_reason: null,
    ...overrides,
  };
}

const REVOKED_ROW = row({
  status: "REVOKED",
  revoked_at: "2026-10-02T10:00:00+03:00",
  revoked_by: "مدير تجريبي",
  revoke_reason: "ADMINISTRATIVE",
});
const SELF_OTP_ROW = row({ id: "00000000-0000-4000-8000-000000000002", status: "STALE", verification_method: "SELF_OTP", verified_by: null, stale_at: "2026-09-30T08:00:00+03:00" });
const NEW_TRUSTED_ROW = row({ id: "00000000-0000-4000-8000-000000000003", verification_method: "STAFF_CALLBACK" });

function trust(state: MobileTrustState, history: MobileTrustRecord[] = [], mobile_masked: string | null = MASK): PersonMobileTrust {
  return { person_code: "PER-000123", state, mobile_masked, history };
}

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول", permissions };
}

/** Serves the person and the CURRENT trust state; `server.trust` may change between requests. */
function renderProfile(initial: PersonMobileTrust, permissions = ALL) {
  const server = { trust: initial };
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === PERSON_PATH) return { data: person() } as never;
    if (path === TRUST_PATH) return { data: server.trust } as never;
    throw new Error(`Unexpected GET: ${path}`);
  });
  const post = vi.spyOn(apiClient, "post");
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <PersonProfileView personCode="PER-000123" />
    </AuthContext.Provider>
  );

  return { server, get, post, ...result };
}

const card = () => screen.findByRole("region", { name: "توثيق رقم الجوال" });
const grantButton = () => screen.queryByRole("button", { name: "توثيق رقم الجوال" });
const revokeButton = () => screen.queryByRole("button", { name: "إلغاء التوثيق" });
const trustGets = (get: { mock: { calls: unknown[][] } }) => get.mock.calls.filter(([path]) => path === TRUST_PATH).length;

beforeEach(() => {
  vi.restoreAllMocks();
});

describe("current state", () => {
  it.each([
    ["TRUSTED", "موثّق"],
    ["STALE", "يحتاج إعادة توثيق"],
    ["REVOKED", "ملغى"],
    ["UNVERIFIED", "غير موثّق"],
    ["NO_MOBILE", "لا يوجد رقم جوال صالح"],
    ["UNAVAILABLE", "تعذّر تحديد حالة التوثيق"],
  ] as const)("renders %s as «%s» from the backend state", async (state, label) => {
    renderProfile(trust(state, [], state === "NO_MOBILE" ? null : MASK));

    const region = await card();
    const badge = await within(region).findByText(label, { selector: "[data-mobile-trust-state] *" });
    expect(badge).toBeInTheDocument();
  });

  it("shows only the backend mask of the current registered mobile", async () => {
    renderProfile(trust("UNVERIFIED"));

    const region = await card();
    expect(await within(region).findByText(MASK)).toBeInTheDocument();
    expect(within(region).getByText("رقم الجوال المسجّل حاليًا")).toBeInTheDocument();
    expect(within(region).queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("is not rendered, and never requested, without person-mobile-trust.view", async () => {
    const { get } = renderProfile(trust("TRUSTED"), ["person.view", "person-mobile-trust.grant", "person-mobile-trust.revoke"]);

    expect(await screen.findByText("البيانات الشخصية")).toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "توثيق رقم الجوال" })).not.toBeInTheDocument();
    expect(trustGets(get)).toBe(0);
  });
});

describe("history", () => {
  it("lists the records newest first with their Staff-facing facts", async () => {
    renderProfile(trust("REVOKED", [REVOKED_ROW, SELF_OTP_ROW]));

    const region = await card();
    const rows = await within(region).findAllByRole("listitem");
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveAttribute("data-mobile-trust-row", "REVOKED");
    expect(within(rows[0]).getByText("إلغاء إداري")).toBeInTheDocument();
    expect(within(rows[0]).getByText("مدير تجريبي")).toBeInTheDocument();
    expect(within(rows[0]).getByText("مسؤول تجريبي")).toBeInTheDocument();
    expect(within(rows[0]).getByText("تحقق حضوري")).toBeInTheDocument();
    expect(rows[1]).toHaveAttribute("data-mobile-trust-row", "STALE");
  });

  it("labels a SELF_OTP record in history", async () => {
    renderProfile(trust("STALE", [SELF_OTP_ROW]));

    expect(await screen.findByText("تحقق ذاتي برمز التفعيل")).toBeInTheDocument();
  });

  it("renders a record without method or verifier with a dash", async () => {
    renderProfile(trust("STALE", [row({ status: "STALE", verification_method: null, verified_at: null, verified_by: null })]));

    const rows = await within(await card()).findAllByRole("listitem");
    expect(within(rows[0]).getByText("—")).toBeInTheDocument();
  });

  it("says when there is no history", async () => {
    renderProfile(trust("UNVERIFIED"));

    expect(await screen.findByText("لا توجد سجلات توثيق سابقة لهذا الشخص.")).toBeInTheDocument();
  });
});

describe("who sees which action", () => {
  it.each(["UNVERIFIED", "STALE", "REVOKED"] as const)("offers an enabled grant for %s", async (state) => {
    renderProfile(trust(state));
    await card();

    await waitFor(() => expect(grantButton()).toBeEnabled());
    expect(revokeButton()).not.toBeInTheDocument();
  });

  it("offers revoke, and no grant, for TRUSTED", async () => {
    renderProfile(trust("TRUSTED", [row()]));
    await card();

    await waitFor(() => expect(revokeButton()).toBeInTheDocument());
    expect(grantButton()).not.toBeInTheDocument();
  });

  it("shows the grant disabled with an explanation for NO_MOBILE", async () => {
    renderProfile(trust("NO_MOBILE", [], null));
    await card();

    await waitFor(() => expect(grantButton()).toBeDisabled());
    expect(screen.getByText(/حدّث رقم الجوال أولًا ثم وثّقه/)).toBeInTheDocument();
  });

  it("offers no action for UNAVAILABLE", async () => {
    renderProfile(trust("UNAVAILABLE"));
    await card();

    expect(await screen.findByText(/لا تتوفر إجراءات التوثيق/)).toBeInTheDocument();
    expect(grantButton()).not.toBeInTheDocument();
    expect(revokeButton()).not.toBeInTheDocument();
  });

  it("offers no grant without person-mobile-trust.grant", async () => {
    renderProfile(trust("REVOKED"), ["person.view", "person-mobile-trust.view", "person-mobile-trust.revoke"]);
    await within(await card()).findByText(MASK);

    expect(grantButton()).not.toBeInTheDocument();
  });

  it("offers no revoke without person-mobile-trust.revoke", async () => {
    renderProfile(trust("TRUSTED", [row()]), ["person.view", "person-mobile-trust.view", "person-mobile-trust.grant"]);
    await within(await card()).findAllByText(MASK);

    expect(revokeButton()).not.toBeInTheDocument();
    expect(grantButton()).not.toBeInTheDocument();
  });
});

describe("grant", () => {
  async function openGrant() {
    await userEvent.click(await screen.findByRole("button", { name: "توثيق رقم الجوال" }));
    return screen.findByRole("dialog");
  }

  it("offers exactly the three Staff methods — never SELF_OTP — and no number field", async () => {
    renderProfile(trust("REVOKED", [REVOKED_ROW]));
    const dialog = await openGrant();

    const methods = within(dialog).getAllByRole("radio").map((radio) => (radio as HTMLInputElement).value);
    expect(methods).toEqual(["IN_PERSON", "STAFF_CALLBACK", "AUTHORIZED_RECORD_REVIEW"]);
    expect(within(dialog).queryByText(/SELF_OTP|برمز التفعيل/)).not.toBeInTheDocument();
    expect(within(dialog).queryByRole("textbox")).not.toBeInTheDocument();
    expect(within(dialog).getByText(/رقم الجوال المسجّل حاليًا في سجل الشخص فقط/)).toBeInTheDocument();
    expect(within(dialog).getByText(/تحققت فعلًا/)).toBeInTheDocument();
    expect(within(dialog).getByText(/سجل الأحداث الأمنية/)).toBeInTheDocument();
  });

  it("requires a method and the operator's attestation before sending anything", async () => {
    const { post } = renderProfile(trust("REVOKED"));
    const dialog = await openGrant();

    await userEvent.click(within(dialog).getByRole("button", { name: "توثيق الرقم الحالي" }));

    expect(await within(dialog).findByText("اختر طريقة التحقق")).toBeInTheDocument();
    expect(within(dialog).getByText("يجب تأكيد أن التحقق تم فعلًا قبل التوثيق")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends only the method, then shows the refetched TRUSTED state and the new record", async () => {
    const { server, get, post } = renderProfile(trust("REVOKED", [REVOKED_ROW]));
    const after = trust("TRUSTED", [NEW_TRUSTED_ROW, REVOKED_ROW]);
    post.mockImplementation(async () => {
      server.trust = after;
      return { data: after } as never;
    });
    const dialog = await openGrant();
    const before = trustGets(get);

    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق عبر اتصال الموظف" }));
    await userEvent.click(within(dialog).getByRole("checkbox"));
    await userEvent.click(within(dialog).getByRole("button", { name: "توثيق الرقم الحالي" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(TRUST_PATH, { verification_method: "STAFF_CALLBACK" });

    const region = await card();
    expect(await within(region).findByText("وُثّق رقم الجوال المسجّل حاليًا للشخص.")).toBeInTheDocument();
    expect(within(region).getByText("موثّق", { selector: "[data-mobile-trust-state] *" })).toBeInTheDocument();
    const rows = within(region).getAllByRole("listitem");
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveAttribute("data-mobile-trust-row", "TRUSTED");
    expect(rows[1]).toHaveAttribute("data-mobile-trust-row", "REVOKED");
    await waitFor(() => expect(trustGets(get)).toBeGreaterThan(before));
  });

  it("surfaces PERSON_NOT_ELIGIBLE from the backend and refetches on close", async () => {
    const { get, post } = renderProfile(trust("REVOKED"));
    const message = "لا يمكن توثيق جوال هذا الشخص: السجل محذوف أو غير نشط أو الشخص غير حي.";
    post.mockRejectedValue(new ApiError(422, { message, code: "PERSON_NOT_ELIGIBLE" }));
    const dialog = await openGrant();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("checkbox"));
    await userEvent.click(within(dialog).getByRole("button", { name: "توثيق الرقم الحالي" }));

    expect(await within(dialog).findByText(message)).toBeInTheDocument();
    const before = trustGets(get);
    await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء" }));
    await waitFor(() => expect(trustGets(get)).toBeGreaterThan(before));
  });

  it("explains an ALREADY_TRUSTED conflict and refetches the authoritative state on close", async () => {
    const { server, get, post } = renderProfile(trust("STALE"));
    post.mockImplementation(async () => {
      server.trust = trust("TRUSTED", [row()]);
      throw new ApiError(409, { message: "x", code: "ALREADY_TRUSTED" });
    });
    const dialog = await openGrant();

    await userEvent.click(within(dialog).getByRole("radio", { name: "مراجعة سجل موثوق" }));
    await userEvent.click(within(dialog).getByRole("checkbox"));
    await userEvent.click(within(dialog).getByRole("button", { name: "توثيق الرقم الحالي" }));

    expect(await within(dialog).findByText(/موثّق مسبقًا/)).toBeInTheDocument();
    const before = trustGets(get);
    await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء" }));

    await waitFor(() => expect(trustGets(get)).toBeGreaterThan(before));
    expect(await screen.findByRole("button", { name: "إلغاء التوثيق" })).toBeInTheDocument();
  });

  it("explains an authorization failure", async () => {
    const { post } = renderProfile(trust("UNVERIFIED"));
    post.mockRejectedValue(new ApiError(403, { message: "Forbidden" }));
    const dialog = await openGrant();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("checkbox"));
    await userEvent.click(within(dialog).getByRole("button", { name: "توثيق الرقم الحالي" }));

    expect(await within(dialog).findByText("لا تملك صلاحية توثيق رقم الجوال.")).toBeInTheDocument();
  });
});

describe("revoke", () => {
  async function openRevoke() {
    await userEvent.click(await screen.findByRole("button", { name: "إلغاء التوثيق" }));
    return screen.findByRole("dialog");
  }

  it("offers exactly the four backend reasons and states the consequences", async () => {
    renderProfile(trust("TRUSTED", [row()]));
    const dialog = await openRevoke();

    const reasons = within(dialog).getAllByRole("radio").map((radio) => (radio as HTMLInputElement).value);
    expect(reasons).toEqual(["REPORTED_LOST", "NOT_OWNER", "VERIFICATION_ERROR", "ADMINISTRATIVE"]);
    expect(within(dialog).getByText(/استعادة كلمة المرور/)).toBeInTheDocument();
    expect(within(dialog).getByText(/لا يعطّل حساب الأسرة، ولا يُنهي بذاته جلسات الدخول القائمة/)).toBeInTheDocument();
  });

  it("requires a reason", async () => {
    const { post } = renderProfile(trust("TRUSTED", [row()]));
    const dialog = await openRevoke();

    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await within(dialog).findByText("اختر سبب الإلغاء")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends the reason, then shows REVOKED with the record kept in history", async () => {
    const { server, post } = renderProfile(trust("TRUSTED", [row()]));
    const after = trust("REVOKED", [row({ status: "REVOKED", revoked_at: "2026-10-06T10:00:00+03:00", revoked_by: "موظف تجريبي", revoke_reason: "NOT_OWNER" })]);
    post.mockImplementation(async () => {
      server.trust = after;
      return { data: after } as never;
    });
    const dialog = await openRevoke();

    await userEvent.click(within(dialog).getByRole("radio", { name: "الرقم لا يعود لهذا الشخص" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(post).toHaveBeenCalledWith(REVOKE_PATH, { reason: "NOT_OWNER" });

    const region = await card();
    expect(await within(region).findByText(/أُلغي توثيق رقم الجوال/)).toBeInTheDocument();
    expect(within(region).getByText("ملغى", { selector: "[data-mobile-trust-state] *" })).toBeInTheDocument();
    const rows = within(region).getAllByRole("listitem");
    expect(rows).toHaveLength(1);
    expect(rows[0]).toHaveAttribute("data-mobile-trust-row", "REVOKED");
    expect(within(rows[0]).getByText("الرقم لا يعود لهذا الشخص")).toBeInTheDocument();
    // A revoked trust can only be followed by a new grant.
    expect(grantButton()).toBeInTheDocument();
    expect(revokeButton()).not.toBeInTheDocument();
  });

  it("explains a NOT_TRUSTED conflict and refetches on close", async () => {
    const { server, get, post } = renderProfile(trust("TRUSTED", [row()]));
    post.mockImplementation(async () => {
      server.trust = trust("REVOKED", [REVOKED_ROW]);
      throw new ApiError(409, { message: "x", code: "NOT_TRUSTED" });
    });
    const dialog = await openRevoke();

    await userEvent.click(within(dialog).getByRole("radio", { name: "إلغاء إداري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await within(dialog).findByText(/لا يوجد توثيق جوال ساري/)).toBeInTheDocument();
    const before = trustGets(get);
    await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء" }));

    await waitFor(() => expect(trustGets(get)).toBeGreaterThan(before));
    expect(await screen.findByText("ملغى", { selector: "[data-mobile-trust-state] *" })).toBeInTheDocument();
  });

  it("maps a validation error onto the reason", async () => {
    const { post } = renderProfile(trust("TRUSTED", [row()]));
    post.mockRejectedValue(new ApiError(422, { message: "سبب الإلغاء غير صالح.", errors: { reason: ["سبب الإلغاء غير صالح."] } }));
    const dialog = await openRevoke();

    await userEvent.click(within(dialog).getByRole("radio", { name: "إلغاء إداري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await within(dialog).findAllByText("سبب الإلغاء غير صالح.")).not.toHaveLength(0);
  });
});

describe("privacy", () => {
  it("never renders a full number or fingerprint material, even if the API sent one", async () => {
    const leaky = {
      ...trust("TRUSTED", [{ ...row(), mobile_fingerprint: FINGERPRINT, key_version: 7 } as MobileTrustRecord]),
      mobile: "0599123467",
    } as PersonMobileTrust;
    const { container } = renderProfile(leaky);

    await within(await card()).findAllByText(MASK);
    const html = container.innerHTML;
    expect(html).not.toContain("0599123467");
    expect(html).not.toContain("599123467");
    expect(html).not.toContain(FINGERPRINT);
    expect(html).not.toContain("key_version");
    expect(html).not.toContain("fingerprint");
  });
});
