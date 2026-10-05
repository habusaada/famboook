import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { REVEAL_ERROR, REVEAL_TOO_MANY } from "@/components/family/account/sensitive-value";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyMembers } from "@/components/family/members/family-members";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyMember } from "@/lib/api/family-household";
import { browserStorageDump, familyGet, familyMember, familyMembers, renderWithClient } from "./helpers";

// PWA-3B.4: revealing another household member's sensitive value from the
// member detail sheet — POST /api/v1/family/household/members/{memberRef}/reveal.
// The full value lives only in the field's component state. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family/members" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const SPOUSE_REF = "5".repeat(64);
const CHILD_REF = "6".repeat(64);
const HEAD_REF = "7".repeat(64);

const HEAD = familyMember({
  member_ref: HEAD_REF,
  full_name: "سالم الاختبار",
  relationship: { code: "HEAD", name: "رب الأسرة" },
  is_household_head: true,
  national_id_masked: "*****6789",
  mobile_masked: "05*****567",
  alternate_mobile_masked: "05*****321",
});
const SPOUSE = familyMember({
  member_ref: SPOUSE_REF,
  full_name: "زوجة الاختبار",
  relationship: { code: "SPOUSE", name: "زوج/زوجة" },
  gender: "FEMALE",
  national_id_masked: "*****6554",
  mobile_masked: "05*****334",
  alternate_mobile_masked: "05*****556",
  alternate_mobile_owner_relation: "أخ",
});
const CHILD = familyMember({
  member_ref: CHILD_REF,
  full_name: "ابن الاختبار",
  national_id_masked: "*****5443",
  mobile_masked: null,
  alternate_mobile_masked: null,
});

const FULL: Record<string, Record<string, string>> = {
  [SPOUSE_REF]: { NATIONAL_ID: "807766554", MOBILE: "0591122334", ALTERNATE_MOBILE: "0563344556" },
  [CHILD_REF]: { NATIONAL_ID: "406655443" },
};
const ALL_FULL = ["807766554", "0591122334", "0563344556", "406655443"];

const revealPath = (ref: string) => `/api/v1/family/household/members/${ref}/reveal`;

function renderMembers(members: FamilyMember[] = [HEAD, SPOUSE, CHILD]) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ members: familyMembers(members) }));
  return { get, ...renderWithClient(<FamilyGate><FamilyMembers /></FamilyGate>) };
}

/** apiClient.post answering a member reveal with that member's synthetic value. */
function revealServer() {
  return vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body: unknown) => {
    const ref = String(path).split("/").at(-2) as string;
    const field = (body as { field: string }).field;
    return { data: { field, value: FULL[ref]?.[field] ?? null } };
  });
}

async function openDetails(name: string) {
  // The first render of the file also loads the gate; allow it time.
  await userEvent.click(await screen.findByRole("button", { name: `تفاصيل ${name}` }, { timeout: 4000 }));
  return screen.findByRole("dialog");
}

async function closeSheet() {
  await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إغلاق" }));
  await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
}

const field = (container: HTMLElement, name: string) => container.querySelector(`[data-field="${name}"] dd`) as HTMLElement;
const control = (sheet: HTMLElement, name: string) => within(sheet).getByRole("button", { name });

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("which values offer a reveal", () => {
  it("offers one unpressed Eye per recorded value of another member, none for a value that is not recorded", async () => {
    renderMembers();
    const sheet = await openDetails("ابن الاختبار");

    expect(control(sheet, "إظهار رقم الهوية")).toHaveAttribute("aria-pressed", "false");
    expect(within(sheet).queryByRole("button", { name: "إظهار رقم الجوال" })).not.toBeInTheDocument();
    expect(within(sheet).queryByRole("button", { name: "إظهار الجوال البديل" })).not.toBeInTheDocument();
    expect(field(sheet, "mobile")).toHaveTextContent("غير مسجّل");
  });

  it("offers no member Eye for the head's own values, only the way to «بياناتي الشخصية»", async () => {
    const post = revealServer();
    renderMembers();
    const sheet = await openDetails("سالم الاختبار");

    expect(within(sheet).queryByRole("button", { name: /إظهار|إخفاء/ })).not.toBeInTheDocument();
    expect(field(sheet, "national_id")).toHaveTextContent("*****6789");
    expect(within(sheet).getByRole("link", { name: /بياناتي الشخصية/ })).toHaveAttribute("href", "/family/account/me");
    expect(post).not.toHaveBeenCalled();
  });
});

describe("revealing", () => {
  it("reveals each field independently with only member_ref in the path and the field in the body", async () => {
    const post = revealServer();
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    await waitFor(() => expect(field(sheet, "national_id")).toHaveTextContent("807766554"));
    expect(field(sheet, "mobile")).toHaveTextContent("05*****334");
    expect(field(sheet, "alternate_mobile")).toHaveTextContent("05*****556");

    await userEvent.click(control(sheet, "إظهار رقم الجوال"));
    await waitFor(() => expect(field(sheet, "mobile")).toHaveTextContent("0591122334"));
    await userEvent.click(control(sheet, "إظهار الجوال البديل"));
    await waitFor(() => expect(field(sheet, "alternate_mobile")).toHaveTextContent("0563344556"));

    expect(post.mock.calls).toEqual([
      [revealPath(SPOUSE_REF), { field: "NATIONAL_ID" }],
      [revealPath(SPOUSE_REF), { field: "MOBILE" }],
      [revealPath(SPOUSE_REF), { field: "ALTERNATE_MOBILE" }],
    ]);
    expect(control(sheet, "إخفاء رقم الهوية")).toHaveAttribute("aria-pressed", "true");
  });

  it("destroys the value on hide and asks again on the next show", async () => {
    const post = revealServer();
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    await waitFor(() => expect(field(sheet, "national_id")).toHaveTextContent("807766554"));
    await userEvent.click(control(sheet, "إخفاء رقم الهوية"));

    expect(field(sheet, "national_id")).toHaveTextContent("*****6554");
    expect(document.body.innerHTML).not.toContain("807766554");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    await waitFor(() => expect(field(sheet, "national_id")).toHaveTextContent("807766554"));
    expect(post).toHaveBeenCalledTimes(2);
  });
});

describe("cleanup", () => {
  it("destroys every revealed value when the sheet closes", async () => {
    revealServer();
    renderMembers();
    let sheet = await openDetails("زوجة الاختبار");
    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    await userEvent.click(control(sheet, "إظهار رقم الجوال"));
    await waitFor(() => expect(field(sheet, "mobile")).toHaveTextContent("0591122334"));

    await closeSheet();
    expect(document.body.innerHTML).not.toMatch(/807766554|0591122334/);

    sheet = await openDetails("زوجة الاختبار");
    expect(field(sheet, "national_id")).toHaveTextContent("*****6554");
    expect(field(sheet, "mobile")).toHaveTextContent("05*****334");
  });

  it("never carries a revealed value over to another member", async () => {
    revealServer();
    renderMembers();
    const spouse = await openDetails("زوجة الاختبار");
    await userEvent.click(control(spouse, "إظهار رقم الهوية"));
    await waitFor(() => expect(field(spouse, "national_id")).toHaveTextContent("807766554"));
    await closeSheet();

    const child = await openDetails("ابن الاختبار");
    expect(field(child, "national_id")).toHaveTextContent("*****5443");
    expect(document.body.innerHTML).not.toContain("807766554");
    expect(control(child, "إظهار رقم الهوية")).toHaveAttribute("aria-pressed", "false");
  });

  it("drops a late answer that arrives after the sheet closed", async () => {
    let answer: (value: unknown) => void = () => {};
    vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (answer = resolve)));
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    await closeSheet();
    await act(async () => answer({ data: { field: "NATIONAL_ID", value: "807766554" } }));

    expect(document.body.innerHTML).not.toContain("807766554");
    const again = await openDetails("زوجة الاختبار");
    expect(field(again, "national_id")).toHaveTextContent("*****6554");
  });

  it("drops a late answer that arrives after switching to another member", async () => {
    let answer: (value: unknown) => void = () => {};
    vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (answer = resolve)));
    renderMembers();
    const spouse = await openDetails("زوجة الاختبار");

    await userEvent.click(control(spouse, "إظهار رقم الهوية"));
    await closeSheet();
    const child = await openDetails("ابن الاختبار");
    await act(async () => answer({ data: { field: "NATIONAL_ID", value: "807766554" } }));

    expect(document.body.innerHTML).not.toContain("807766554");
    expect(field(child, "national_id")).toHaveTextContent("*****5443");
  });

  it("ignores repeated presses while a reveal is in flight (no hide while busy)", async () => {
    let answer: (value: unknown) => void = () => {};
    const post = vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (answer = resolve)));
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    expect(control(sheet, "إظهار رقم الهوية")).toBeDisabled();
    await userEvent.click(control(sheet, "إظهار رقم الهوية"));
    expect(post).toHaveBeenCalledTimes(1);
    expect(control(sheet, "إظهار رقم الجوال")).toBeEnabled();

    await act(async () => answer({ data: { field: "NATIONAL_ID", value: "807766554" } }));
    expect(field(sheet, "national_id")).toHaveTextContent("807766554");
  });
});

describe("failures", () => {
  it("keep the mask on a generic 404 and say nothing about why", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(
      new ApiError(404, { message: "بيانات هذا الفرد غير متاحة.", code: "HOUSEHOLD_MEMBER_UNAVAILABLE" })
    );
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الهوية"));

    expect(await within(field(sheet, "national_id")).findByRole("alert")).toHaveTextContent(REVEAL_ERROR);
    expect(field(sheet, "national_id")).toHaveTextContent("*****6554");
  });

  it("explain a 429 and allow a later retry", async () => {
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(new ApiError(429, { message: "x", code: "TOO_MANY_REQUESTS" }));
    renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(control(sheet, "إظهار رقم الجوال"));
    expect(await within(field(sheet, "mobile")).findByRole("alert")).toHaveTextContent(REVEAL_TOO_MANY);
    expect(field(sheet, "mobile")).toHaveTextContent("05*****334");

    post.mockResolvedValueOnce({ data: { field: "MOBILE", value: "0591122334" } });
    await userEvent.click(control(sheet, "إظهار رقم الجوال"));
    await waitFor(() => expect(field(sheet, "mobile")).toHaveTextContent("0591122334"));
  });
});

describe("privacy", () => {
  it("never puts a value or a member reference in storage, a cache, a URL, an attribute or the clipboard", async () => {
    const writeText = vi.fn();
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    const post = revealServer();
    const { client, get } = renderMembers();
    const sheet = await openDetails("زوجة الاختبار");

    for (const name of ["إظهار رقم الهوية", "إظهار رقم الجوال", "إظهار الجوال البديل"]) {
      await userEvent.click(control(sheet, name));
    }
    await waitFor(() => expect(field(sheet, "alternate_mobile")).toHaveTextContent("0563344556"));

    expect(writeText).not.toHaveBeenCalled();
    const cached = JSON.stringify([
      client.getQueryCache().getAll().map((q) => q.state.data),
      client.getMutationCache().getAll().map((m) => m.state.data),
    ]);
    for (const full of ALL_FULL) {
      expect(cached).not.toContain(full);
      expect(browserStorageDump()).not.toContain(full);
      expect(document.body.innerHTML).not.toMatch(new RegExp(`="[^"]*${full}`));
    }
    // The member reference is used only in the reveal path, never shown or queried.
    for (const ref of [SPOUSE_REF, CHILD_REF, HEAD_REF]) {
      expect(document.body.innerHTML).not.toContain(ref);
    }
    expect(get.mock.calls.map(([path]) => String(path)).join(" ")).not.toMatch(/[0-9a-f]{64}/);
    expect(post.mock.calls.every(([path]) => !String(path).includes("?"))).toBe(true);
  });
});
