import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AccountView } from "@/components/family/account/account-view";
import { FamilyGate } from "@/components/family/family-gate";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyAccount, FamilyMobileTrustState } from "@/lib/api/family-account";
import type { FamilyUser } from "@/lib/api/family-auth";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump, coordinatorContext, familyAccount, familyGet, familyUser, renderWithClient } from "./helpers";

// PWA-3B.5: /family/account «حسابي» on GET /api/v1/family/account, /me and
// (coordinators only) /family/coordinator/context. Synthetic data only.

const nav = { pathname: "/family/account" };
const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => nav.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const COORDINATOR: Partial<FamilyUser> = { roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true };

function renderAccount({
  user = familyUser(),
  account = familyAccount() as FamilyAccount | Error | (() => Promise<unknown>),
  coordinator = coordinatorContext() as unknown,
}: { user?: FamilyUser; account?: FamilyAccount | Error | (() => Promise<unknown>); coordinator?: unknown } = {}) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ user, account, coordinator }));
  const result = renderWithClient(
    <FamilyGate>
      <AccountView />
    </FamilyGate>
  );

  return { get, ...result };
}

const section = (id: string) => document.querySelector(`[data-account-section="${id}"]`) as HTMLElement;
const field = (name: string) => document.querySelector(`[data-field="${name}"] dd`);
const loaded = () => screen.findByText("موثّق");

beforeEach(() => {
  vi.restoreAllMocks();
  router.replace.mockReset();
  nav.pathname = "/family/account";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the account", () => {
  it("renders «حسابي» from GET /api/v1/family/account, which sends no identifier", async () => {
    const { get } = renderAccount();

    expect(await screen.findByRole("heading", { level: 1, name: "حسابي" })).toBeInTheDocument();
    await loaded();
    expect(get).toHaveBeenCalledWith("/api/v1/family/account");
    expect(get.mock.calls.every(([path]) => !/\d|PER-|FAM-|\?/.test(String(path).replace("/api/v1", "")))).toBe(true);
  });

  it("shows who is signed in, the active state, the activation date and the family code", async () => {
    renderAccount();
    await loaded();

    const identity = section("identity");
    expect(within(identity).getByRole("heading", { name: "سالم الاختبار" })).toBeInTheDocument();
    expect(within(identity).getByText("الحساب مفعّل")).toBeInTheDocument();
    expect(field("activated_at")).toHaveTextContent(formatDateLong("2026-10-01"));
    expect(field("family_code")).toHaveTextContent("FAM-000123");
  });

  it("says «غير مسجّل» for a missing activation date — never «غير معروف»", async () => {
    renderAccount({ account: familyAccount({ activated_at: null }) });
    await loaded();

    expect(field("activated_at")).toHaveTextContent("غير مسجّل");
    expect(section("identity")).not.toHaveTextContent("غير معروف");
  });

  it("shows the access type in words, never a role or permission string", async () => {
    renderAccount();
    await loaded();

    const access = section("access");
    expect(within(access).getByText("رب الأسرة")).toBeInTheDocument();
    expect(within(access).queryByText("صلاحية التنسيق")).not.toBeInTheDocument();
    for (const raw of ["FAMILY_USER", "COORDINATOR", "family-portal", "permission", "role"]) {
      expect(document.body).not.toHaveTextContent(raw);
    }
  });

  it("links «بياناتي الشخصية» to its existing route instead of repeating the Person record", async () => {
    renderAccount();
    await loaded();

    expect(screen.getByRole("link", { name: /بياناتي الشخصية/ })).toHaveAttribute("href", "/family/account/me");
    for (const label of ["رقم الهوية", "تاريخ الميلاد", "الحالة الاجتماعية", "الجوال البديل"]) {
      expect(screen.queryByText(label)).not.toBeInTheDocument();
    }
  });

  it("shows a loading state, then an inline error with retry for a server failure", async () => {
    let fail = true;
    renderAccount({
      account: () => (fail ? Promise.reject(new ApiError(500, { message: "x" })) : Promise.resolve({ data: familyAccount() })),
    });

    expect(await screen.findByText("تعذّر تحميل بيانات الحساب")).toBeInTheDocument();
    fail = false;
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await loaded()).toBeInTheDocument();
  });

  it("leaves a lost Family context to the shell: the neutral notice, no account data", async () => {
    renderAccount({ account: new ApiError(403, { message: "x", code: "FAMILY_CONTEXT_UNAVAILABLE" }) });

    expect(await screen.findByText("الوصول غير متاح حاليًا")).toBeInTheDocument();
    expect(screen.queryByText("تعذّر تحميل بيانات الحساب")).not.toBeInTheDocument();
    expect(screen.queryByText("الحساب مفعّل")).not.toBeInTheDocument();
  });
});

describe("mobile trust (owner summary)", () => {
  it.each([
    ["TRUSTED", "موثّق"],
    ["STALE", "يحتاج إعادة توثيق"],
    ["REVOKED", "غير موثّق حاليًا"],
    ["UNVERIFIED", "غير موثّق"],
    ["NO_MOBILE", "لا يوجد رقم جوال صالح"],
    ["UNAVAILABLE", "تعذّر عرض حالة التوثيق حاليًا"],
  ] as const)("shows %s as «%s»", async (state: FamilyMobileTrustState, label) => {
    renderAccount({ account: familyAccount({ mobile: { state, masked: state === "NO_MOBILE" ? null : "05*****567" } }) });

    const badge = await screen.findByText(label, { selector: "[data-account-mobile-state]" });
    expect(badge).toHaveAttribute("data-account-mobile-state", state);
  });

  it("never uses the Staff lifecycle wording for a revoked trust", async () => {
    renderAccount({ account: familyAccount({ mobile: { state: "REVOKED", masked: "05*****567" } }) });
    await screen.findByText("غير موثّق حاليًا");

    expect(document.body).not.toHaveTextContent("ملغى");
    expect(screen.getByText(/يُرجى مراجعة إدارة السجل/)).toBeInTheDocument();
  });

  it("shows only the masked current mobile", async () => {
    renderAccount();
    await loaded();

    expect(field("mobile")).toHaveTextContent("05*****567");
  });

  it("says «غير مسجّل» without a mobile", async () => {
    renderAccount({ account: familyAccount({ mobile: { state: "NO_MOBILE", masked: null } }) });
    await screen.findByText("لا يوجد رقم جوال صالح");
    expect(field("mobile")).toHaveTextContent("غير مسجّل");
  });

  it("explains recovery use and offers no trust history, Staff control, reveal or edit", async () => {
    renderAccount();
    await loaded();

    const mobile = section("mobile");
    expect(within(mobile).getByText(/لاستلام رموز التحقق/)).toBeInTheDocument();
    expect(within(mobile).queryAllByRole("button")).toHaveLength(0);
    expect(within(mobile).queryAllByRole("link")).toHaveLength(0);
    expect(within(mobile).queryByRole("textbox")).not.toBeInTheDocument();
    for (const text of ["سجل التوثيق", "إلغاء التوثيق", "توثيق رقم الجوال المسجّل", "وثّقه", "ألغاه", "سبب", "إظهار", "تعديل"]) {
      expect(within(mobile).queryByText(new RegExp(text))).not.toBeInTheDocument();
    }
  });
});

describe("password and recovery (decision: not an account setting)", () => {
  it("offers no Forgot / Reset Password action and no link to /family/forgot-password", async () => {
    renderAccount();
    await loaded();

    expect(document.querySelector('a[href*="forgot-password"]')).toBeNull();
    for (const text of [/نسيت كلمة المرور/, /إعادة تعيين كلمة المرور/, /استعادة كلمة المرور/]) {
      expect(screen.queryByRole("link", { name: text })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: text })).not.toBeInTheDocument();
    }
  });

  it("introduces no fake or disabled password-change control", async () => {
    renderAccount();
    await loaded();

    expect(screen.queryByText(/كلمة المرور/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /تغيير/ })).not.toBeInTheDocument();
    const disabled = Array.from(document.querySelectorAll("[data-account] button:disabled, [data-account] [aria-disabled='true']"));
    expect(disabled).toHaveLength(0);
  });

  it("still shows the mobile trust state without any password action", async () => {
    renderAccount({ account: familyAccount({ mobile: { state: "STALE", masked: "05*****321" } }) });

    expect(await screen.findByText("يحتاج إعادة توثيق")).toBeInTheDocument();
    expect(field("mobile")).toHaveTextContent("05*****321");
    expect(document.querySelector('a[href*="forgot-password"]')).toBeNull();
  });
});

describe("coordinator", () => {
  it("shows nothing of coordination to a normal household head and never asks for a scope", async () => {
    const { get } = renderAccount();
    await loaded();

    expect(section("coordinator")).toBeNull();
    expect(screen.queryByText("مساحة التنسيق")).not.toBeInTheDocument();
    expect(get).not.toHaveBeenCalledWith("/api/v1/family/coordinator/context");
  });

  it("shows the server's effective scope, the family count and the entry when Coordinator Space is open", async () => {
    const { get } = renderAccount({
      user: familyUser(COORDINATOR),
      coordinator: coordinatorContext({
        scopes: [
          { type: "CLAN", code: "C1", name: "عشيرة الاختبار", clan_name: "عشيرة الاختبار" },
          { type: "BRANCH", code: "B1", name: "فرع الاختبار", clan_name: "عشيرة الاختبار" },
        ],
        family_count: 7,
      }),
    });

    const scopes = await screen.findByText("عشيرة: عشيرة الاختبار");
    expect(scopes).toBeInTheDocument();
    expect(screen.getByText("فرع: فرع الاختبار")).toBeInTheDocument();
    expect(document.querySelector("[data-account-coordinator-count]")).toHaveTextContent("7");
    expect(screen.getByRole("link", { name: "فتح مساحة التنسيق" })).toHaveAttribute("href", "/family/coordinator");
    expect(within(section("access")).getByText("صلاحية التنسيق")).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith("/api/v1/family/coordinator/context");
  });

  it("says there is no effective scope, with no entry, when Coordinator Space is closed", async () => {
    const { get } = renderAccount({ user: familyUser({ ...COORDINATOR, coordinator_space: false }) });
    await loaded();

    expect(within(section("coordinator")).getByText("لا يوجد نطاق تنسيق فعّال حاليًا.")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "فتح مساحة التنسيق" })).not.toBeInTheDocument();
    expect(get).not.toHaveBeenCalledWith("/api/v1/family/coordinator/context");
  });

  it("trusts the server when the scope disappeared in the meantime (403): no entry", async () => {
    renderAccount({ user: familyUser(COORDINATOR), coordinator: new ApiError(403, { message: "x" }) });

    expect(await screen.findByText("لا يوجد نطاق تنسيق فعّال حاليًا.")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "فتح مساحة التنسيق" })).not.toBeInTheDocument();
  });
});

describe("logout", () => {
  it("calls the canonical Family logout, clears the Family state and opens the Family login", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(undefined as never);
    const { client } = renderAccount();
    await loaded();

    await userEvent.click(document.querySelector("[data-account-logout]") as HTMLElement);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith("/api/v1/family/auth/logout", {});
    expect(client.getQueryData(["family", "account"])).toBeUndefined();
    expect(client.getQueryData(["family", "me"])).toBeNull();
  });

  it("still drops the local session when the server call fails", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(500, { message: "x" }));
    const { client } = renderAccount();
    await loaded();

    await userEvent.click(document.querySelector("[data-account-logout]") as HTMLElement);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(client.getQueryData(["family", "account"])).toBeUndefined();
  });
});

describe("navigation and privacy", () => {
  it.each(["/family/account", "/family/account/me"])("makes حسابي the current, linked entry on %s", async (pathname) => {
    nav.pathname = pathname;
    renderAccount();
    await loaded();

    const bar = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    const account = within(bar).getByRole("link", { name: "حسابي" });
    expect(account).toHaveAttribute("href", "/family/account");
    expect(account).toHaveAttribute("aria-current", "page");
    expect(within(bar).getByRole("link", { name: "الرئيسية" })).not.toHaveAttribute("aria-current");
    expect(within(bar).getByRole("button", { name: "طلباتي" })).toBeDisabled();
  });

  it("renders no full number, identifier or security internal, even if a response carried some", async () => {
    const leaky = {
      ...familyAccount(),
      mobile: { state: "TRUSTED", masked: "05*****567", full: "0591234567", mobile_fingerprint: "f".repeat(64), key_version: 3 },
      person_code: "PER-000123",
      user_id: 991,
      history: [{ status: "REVOKED", revoke_reason: "NOT_OWNER", revoked_by: "مسؤول" }],
    } as unknown as FamilyAccount;
    renderAccount({ account: leaky });
    await loaded();

    const html = document.body.innerHTML;
    for (const value of ["0591234567", "591234567", "f".repeat(64), "PER-000123", "991", "NOT_OWNER", "مسؤول", "key_version", "fingerprint"]) {
      expect(html).not.toContain(value);
    }
  });

  it("offers no Staff control and keeps nothing in browser storage", async () => {
    renderAccount({ user: familyUser(COORDINATOR) });
    await loaded();
    await screen.findByRole("link", { name: "فتح مساحة التنسيق" });

    for (const text of ["توثيق رقم الجوال المسجّل", "إلغاء التوثيق", "منح", "إدارة الصلاحيات", "تعيين نطاق"]) {
      expect(screen.queryByRole("button", { name: new RegExp(text) })).not.toBeInTheDocument();
    }
    expect(browserStorageDump()).not.toMatch(/05\*|FAM-|TRUSTED|عشيرة/);
  });
});
