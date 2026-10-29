import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import CivilLookupAccessPage from "@/app/(staff)/administration/civil-registry/lookup-access/page";
import { CivilRegistryNav } from "@/components/administration/civil-registry/parts";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { CivilLookupAccountState, CivilLookupPolicyChange, CivilLookupUserAccessRow } from "@/lib/types/api/civil-lookup";
import { renderWithClient } from "./helpers";

// FB-CIVIL-02D — Administration → Civil Registry → Lookup access. SYNTHETIC
// data only (placeholder names, example.test addresses). The API resolves,
// authorizes, validates and records; these tests cover what the page sends
// (the full set of sides and the loaded state) and what it shows.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/administration/civil-registry/lookup-access",
}));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const BASE = "/api/v1/civil-registry/lookup";

const admin = (permissions = ["civil-lookup.usage.view", "civil-lookup.manage"]): CurrentUser =>
  ({ name: "مدير تجريبي", email: "super@example.test", role: "SUPER_ADMIN", role_label: "مدير النظام", permissions }) as CurrentUser;

function renderPage(permissions?: string[], ui: React.ReactElement = <CivilLookupAccessPage />) {
  return renderWithClient(<AuthContext.Provider value={authValue(admin(permissions))}>{ui}</AuthContext.Provider>);
}

const familyState = (over: Partial<CivilLookupAccountState> = {}): CivilLookupAccountState => ({
  user: { id: 9, name: "رب أسرة تجريبي", email: null, is_active: true, account_side: "FAMILY", roles: ["FAMILY_USER", "COORDINATOR"] },
  person: { person_code: "PER-000123", full_name: "رب أسرة تجريبي", link_status: "ACTIVE" },
  access: "DEFAULT",
  sides: [],
  changed_by: null,
  changed_at: null,
  last_change: null,
  grantable_sides: ["FAMILY", "COORDINATOR"],
  role_policy: { role: "FAMILY_USER", daily_limit: 5 },
  effective: { FAMILY: { allowed: false, reason: "LOOKUP_DISABLED" }, COORDINATOR: { allowed: false, reason: "LOOKUP_DISABLED" } },
  ...over,
});

const staffState = (over: Partial<CivilLookupAccountState> = {}): CivilLookupAccountState => ({
  user: { id: 7, name: "مدخل تجريبي", email: "entry@example.test", is_active: true, account_side: "STAFF", roles: ["DATA_ENTRY"] },
  person: null,
  access: "DEFAULT",
  sides: [],
  changed_by: null,
  changed_at: null,
  last_change: null,
  grantable_sides: [],
  role_policy: { role: "DATA_ENTRY", daily_limit: 50 },
  effective: { STAFF: { allowed: true, reason: null } },
  ...over,
});

const overrides: CivilLookupUserAccessRow[] = [
  { user: { id: 9, name: "رب أسرة تجريبي", email: null }, account_side: "FAMILY", person_code: "PER-000123", access: "GRANTED", sides: ["FAMILY"],
    changed_by: "مدير تجريبي", changed_at: "2026-10-27T08:00:00+00:00" },
  { user: { id: 7, name: "مدخل تجريبي", email: "entry@example.test" }, account_side: "STAFF", person_code: null, access: "REVOKED", sides: [],
    changed_by: "مدير تجريبي", changed_at: "2026-10-27T09:00:00+00:00" },
];

const change: CivilLookupPolicyChange = {
  id: 31, subject_type: "USER", role: null, user: { id: 9, name: "رب أسرة تجريبي" }, old_enabled: null, new_enabled: null, old_daily_limit: null,
  new_daily_limit: null, old_access: null, new_access: "GRANTED", old_sides: [], new_sides: ["FAMILY"], changed_by: "مدير تجريبي",
  changed_at: "2026-10-27T08:00:00+00:00",
};

let get: MockInstance<typeof apiClient.get>;
let post: MockInstance<typeof apiClient.post>;
let put: MockInstance<typeof apiClient.put>;

/** Resolve answers per target (a state, an error, or a function for successive answers). */
function mockApi(states: Record<string, CivilLookupAccountState | Error | (() => CivilLookupAccountState)>) {
  get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === `${BASE}/admin/user-access`) return { data: overrides, meta: { limit: 200, total: 2 } } as never;
    if (path.startsWith(`${BASE}/admin/changes`)) return { data: [change], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } as never;
    if (path.startsWith(`${BASE}/admin/accounts/staff`)) {
      return { data: [{ id: 7, name: "مدخل تجريبي", email: "entry@example.test", is_active: true, account_side: "STAFF", roles: ["DATA_ENTRY"] }],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } as never;
    }
    if (path.startsWith("/api/v1/people")) {
      return { data: [{ person_code: "PER-000123", full_name: "رب أسرة تجريبي", family: { family_code: "FAM-000045", branch_name: "فرع تجريبي", is_household_head: true } }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, path: "" } } as never;
    }
    throw new Error(`Unexpected GET: ${path}`);
  });
  post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body?: unknown) => {
    if (path !== `${BASE}/admin/user-access/resolve`) throw new Error(`Unexpected POST: ${path}`);
    const key = (body as { email?: string; person_code?: string }).email ?? (body as { person_code: string }).person_code;
    const reply = states[key];
    if (reply === undefined) throw new Error(`Unexpected target: ${key}`);
    if (reply instanceof Error) throw reply;
    return { data: typeof reply === "function" ? reply() : reply } as never;
  });
  put = vi.spyOn(apiClient, "put");
}

async function findEl(query: () => Element | null): Promise<HTMLElement> {
  return waitFor(() => {
    const el = query();
    expect(el).not.toBeNull();
    return el as HTMLElement;
  });
}

/** Selects the family account through the People search. */
async function selectFamily(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("البحث عن شخص بالاسم أو رمز الشخص"), "PER-000123");
  await user.click(within(document.querySelector("[data-family-search]") as HTMLElement).getByRole("button", { name: "بحث" }));
  const results = await findEl(() => document.querySelector("[data-family-results]"));
  expect(results.textContent).toContain("FAM-000045");
  expect(results.textContent).toContain("فرع تجريبي");
  await user.click(within(results).getByRole("button", { name: "اختيار" }));
  return findEl(() => document.querySelector("[data-access-editor]"));
}

const saveButton = () => screen.getByRole("button", { name: "حفظ التغيير" });

async function confirm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(saveButton());
  const dialog = await findEl(() => document.querySelector("[data-confirm-dialog]"));
  await user.click(within(dialog).getByRole("button", { name: "تأكيد" }));
  return dialog;
}

beforeEach(() => {
  mockApi({ "PER-000123": familyState(), "entry@example.test": staffState() });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("access and navigation", () => {
  it("refuses the page without civil-lookup.manage and shows the nav entry only with it", () => {
    const { unmount } = renderPage(["civil-lookup.usage.view"]);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
    expect(post).not.toHaveBeenCalled();
    unmount();

    const nav = renderPage(["civil-lookup.usage.view"], <CivilRegistryNav />);
    expect(screen.queryByRole("link", { name: "تصاريح الاستعلام المدني" })).toBeNull();
    nav.unmount();
    renderPage(undefined, <CivilRegistryNav />);
    const links = screen.getAllByRole("link").map((a) => a.textContent);
    expect(links.indexOf("تصاريح الاستعلام المدني")).toBe(links.indexOf("كوتة الاستعلامات") + 1);
    expect(screen.getByRole("link", { name: "تصاريح الاستعلام المدني" })).toHaveAttribute("href", "/administration/civil-registry/lookup-access");
  });
});

describe("account selection", () => {
  it("finds a family account through its Person, resolves it on the server and shows DEFAULT", async () => {
    const user = userEvent.setup();
    renderPage();
    expect(screen.getByRole("heading", { name: "تصاريح الاستعلام المدني" })).toBeInTheDocument();
    await selectFamily(user);
    expect(post).toHaveBeenCalledWith(`${BASE}/admin/user-access/resolve`, { person_code: "PER-000123" });
    const panel = document.querySelector("[data-selected-account]") as HTMLElement;
    expect(panel.querySelector('[data-account-side="FAMILY"]')?.textContent).toBe("حساب بوابة الأسرة");
    expect(panel.querySelector('[data-access-state="DEFAULT"]')?.textContent).toBe("بدون تجاوز فردي");
    expect(panel.querySelector("[data-last-change]")?.textContent).toBe("لم يُعدَّل بعد");
    expect(panel.querySelector('[data-effective-context="FAMILY"]')?.textContent).toContain("غير متاح");
    // Nothing changed yet: no save.
    expect(saveButton()).toBeDisabled();
  });

  it("finds a Staff account by name or email and never offers an individual grant", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByRole("button", { name: "حسابات الموظفين" }));
    await user.type(screen.getByLabelText("البحث عن موظف بالاسم أو البريد الإلكتروني"), "entry@");
    await user.click(within(document.querySelector("[data-staff-search]") as HTMLElement).getByRole("button", { name: "بحث" }));
    const results = await findEl(() => document.querySelector("[data-staff-results]"));
    expect(get).toHaveBeenCalledWith(`${BASE}/admin/accounts/staff?q=entry%40&page=1`);
    expect(results.textContent).toContain("مدخل بيانات");
    await user.click(within(results).getByRole("button", { name: "اختيار" }));

    await findEl(() => document.querySelector("[data-access-editor]"));
    expect(post).toHaveBeenCalledWith(`${BASE}/admin/user-access/resolve`, { email: "entry@example.test" });
    expect(document.querySelector("[data-role-policy]")?.textContent).toContain("50");
    expect(screen.queryByRole("radio", { name: /منح فردي صريح/ })).toBeNull();
    expect(screen.getByText("المنح الفردي متاح لحسابات بوابة الأسرة فقط.")).toBeInTheDocument();
  });

  it("opens an existing override from the list with its sides and who changed it", async () => {
    const user = userEvent.setup();
    mockApi({
      "PER-000123": familyState({ access: "GRANTED", sides: ["FAMILY"], changed_by: "مدير تجريبي", changed_at: "2026-10-27T08:00:00+00:00" }),
      "entry@example.test": staffState(),
    });
    renderPage();
    const row = await findEl(() => document.querySelector('[data-access-row="GRANTED"]'));
    const table = document.querySelector("[data-overrides]") as HTMLElement;
    // Digits follow the runtime's Arabic locale data (Latin or Arabic-Indic).
    expect(table.textContent).toMatch(/تُعرض أحدث (200|٢٠٠) تجاوزات من أصل (2|٢)\./);
    expect(table.textContent).toContain("غياب حساب من هذه القائمة لا يعني أنه بلا تجاوز");
    expect(row.textContent).toContain("بوابة الأسرة");
    await user.click(within(row).getByRole("button", { name: "عرض / تعديل" }));

    await findEl(() => document.querySelector("[data-access-editor]"));
    expect(post).toHaveBeenCalledWith(`${BASE}/admin/user-access/resolve`, { person_code: "PER-000123" });
    expect(document.querySelector("[data-current-sides]")?.textContent).toBe("بوابة الأسرة");
    expect(document.querySelector("[data-last-change]")?.textContent).toContain("مدير تجريبي");
    expect(screen.getByRole("checkbox", { name: /بوابة الأسرة/ })).toBeChecked();
  });

  it("does not offer editing until the current state is loaded, and shows a resolve refusal", async () => {
    const user = userEvent.setup();
    mockApi({ "PER-000123": new ApiError(422, { errors: { person_code: ["لا يوجد حساب مرتبط بهذا الشخص."] } }) });
    renderPage();
    await user.type(screen.getByLabelText("البحث عن شخص بالاسم أو رمز الشخص"), "PER-000123");
    await user.click(within(document.querySelector("[data-family-search]") as HTMLElement).getByRole("button", { name: "بحث" }));
    await user.click(within(await findEl(() => document.querySelector("[data-family-results]"))).getByRole("button", { name: "اختيار" }));
    expect((await findEl(() => document.querySelector("[data-account-error]"))).textContent).toContain("لا يوجد حساب مرتبط بهذا الشخص.");
    expect(document.querySelector("[data-access-editor]")).toBeNull();
  });
});

describe("editing", () => {
  it("grants FAMILY to an eligible family account and sends the loaded state", async () => {
    const user = userEvent.setup();
    put.mockResolvedValue({ data: { user_id: 9, access: "GRANTED", sides: ["FAMILY"] } } as never);
    renderPage();
    await selectFamily(user);
    await user.click(screen.getByRole("radio", { name: /منح فردي صريح/ }));
    // GRANTED with no side is DEFAULT: nothing to save yet.
    expect(document.querySelector("[data-no-sides]")).not.toBeNull();
    expect(saveButton()).toBeDisabled();
    await user.click(screen.getByRole("checkbox", { name: /بوابة الأسرة/ }));
    expect(document.querySelector("[data-diff-proposed]")?.textContent).toBe("منح فردي صريح (بوابة الأسرة)");
    await confirm(user);
    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(`${BASE}/admin/user-access`, {
        person_code: "PER-000123", access: "GRANTED", sides: ["FAMILY"], expected_access: "DEFAULT", expected_sides: [],
      }),
    );
    expect((await findEl(() => document.querySelector('[data-access-notice="success"]'))).textContent).toContain("حُفظ التصريح");
  });

  it("adds COORDINATOR without dropping FAMILY, and removes it keeping FAMILY", async () => {
    const user = userEvent.setup();
    let state = familyState({ access: "GRANTED", sides: ["FAMILY"], changed_by: "مدير تجريبي", changed_at: "2026-10-27T08:00:00+00:00" });
    mockApi({ "PER-000123": () => state });
    put.mockImplementation(async (_path: string, body?: unknown) => {
      const { sides } = body as { sides: ("FAMILY" | "COORDINATOR")[] };
      state = { ...state, sides, changed_at: `2026-10-27T1${sides.length}:00:00+00:00` };
      return { data: { user_id: 9, access: "GRANTED", sides } } as never;
    });
    renderPage();
    await selectFamily(user);

    await user.click(screen.getByRole("checkbox", { name: /مساحة التنسيق/ }));
    await confirm(user);
    await waitFor(() =>
      expect(put).toHaveBeenLastCalledWith(`${BASE}/admin/user-access`, {
        person_code: "PER-000123", access: "GRANTED", sides: ["FAMILY", "COORDINATOR"], expected_access: "GRANTED", expected_sides: ["FAMILY"],
      }),
    );

    // The editor restarts from the new server state.
    await waitFor(() => expect(document.querySelector("[data-current-sides]")?.textContent).toBe("بوابة الأسرة، مساحة التنسيق"));
    await user.click(screen.getByRole("checkbox", { name: /مساحة التنسيق/ }));
    expect(document.querySelector("[data-diff-proposed]")?.textContent).toBe("منح فردي صريح (بوابة الأسرة)");
    await confirm(user);
    await waitFor(() =>
      expect(put).toHaveBeenLastCalledWith(`${BASE}/admin/user-access`, {
        person_code: "PER-000123", access: "GRANTED", sides: ["FAMILY"], expected_access: "GRANTED", expected_sides: ["FAMILY", "COORDINATOR"],
      }),
    );
    expect(put).toHaveBeenCalledTimes(2);
  });

  it("does not let COORDINATOR be added for an account without the role", async () => {
    const user = userEvent.setup();
    mockApi({
      "PER-000123": familyState({
        user: { id: 9, name: "رب أسرة تجريبي", email: null, is_active: true, account_side: "FAMILY", roles: ["FAMILY_USER"] },
        grantable_sides: ["FAMILY"],
      }),
    });
    renderPage();
    await selectFamily(user);
    await user.click(screen.getByRole("radio", { name: /منح فردي صريح/ }));
    const coordinator = screen.getByRole("checkbox", { name: /مساحة التنسيق/ });
    expect(coordinator).toBeDisabled();
    expect(coordinator.closest("label")?.textContent).toContain("هذا الحساب لا يحمل دور المنسّق");
    expect(screen.getByRole("checkbox", { name: /بوابة الأسرة/ })).toBeEnabled();
  });

  it("revokes a Staff account after an explicit warning", async () => {
    const user = userEvent.setup();
    put.mockResolvedValue({ data: { user_id: 7, access: "REVOKED", sides: [] } } as never);
    renderPage();
    const row = await findEl(() => document.querySelector('[data-access-row="REVOKED"]'));
    await user.click(within(row).getByRole("button", { name: "عرض / تعديل" }));
    await findEl(() => document.querySelector("[data-access-editor]"));

    await user.click(screen.getByRole("radio", { name: /إيقاف صريح/ }));
    await user.click(saveButton());
    const dialog = await findEl(() => document.querySelector("[data-confirm-dialog]"));
    expect(dialog.querySelector("[data-revoke-warning]")?.textContent).toContain("سيُمنع هذا الحساب من الاستعلام في كل الجهات");
    await user.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(`${BASE}/admin/user-access`, {
        email: "entry@example.test", access: "REVOKED", expected_access: "DEFAULT", expected_sides: [],
      }),
    );
  });

  it("restores DEFAULT from a revoked account", async () => {
    const user = userEvent.setup();
    mockApi({ "entry@example.test": staffState({ access: "REVOKED", changed_by: "مدير تجريبي", changed_at: "2026-10-27T09:00:00+00:00" }) });
    put.mockResolvedValue({ data: { user_id: 7, access: "DEFAULT", sides: [] } } as never);
    renderPage();
    await user.click(within(await findEl(() => document.querySelector('[data-access-row="REVOKED"]'))).getByRole("button", { name: "عرض / تعديل" }));
    await findEl(() => document.querySelector("[data-access-editor]"));
    expect(screen.getByRole("radio", { name: /إيقاف صريح/ })).toBeChecked();
    await user.click(screen.getByRole("radio", { name: /بدون تجاوز فردي/ }));
    await confirm(user);
    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(`${BASE}/admin/user-access`, {
        email: "entry@example.test", access: "DEFAULT", expected_access: "REVOKED", expected_sides: [],
      }),
    );
  });

  it("sends nothing when the confirmation is cancelled, and only once while a save is pending", async () => {
    const user = userEvent.setup();
    let release: (value: unknown) => void = () => {};
    put.mockImplementation(() => new Promise((resolve) => (release = resolve)) as never);
    renderPage();
    await selectFamily(user);
    await user.click(screen.getByRole("radio", { name: /منح فردي صريح/ }));
    await user.click(screen.getByRole("checkbox", { name: /بوابة الأسرة/ }));

    await user.click(saveButton());
    const dialog = await findEl(() => document.querySelector("[data-confirm-dialog]"));
    await user.click(within(dialog).getByRole("button", { name: "إلغاء" }));
    await waitFor(() => expect(document.querySelector("[data-confirm-dialog]")).toBeNull());
    expect(put).not.toHaveBeenCalled();

    await user.click(saveButton());
    const again = await findEl(() => document.querySelector("[data-confirm-dialog]"));
    const confirmButton = within(again).getByRole("button", { name: "تأكيد" });
    await user.click(confirmButton);
    await waitFor(() => expect(confirmButton).toBeDisabled());
    await user.click(confirmButton);
    expect(put).toHaveBeenCalledTimes(1);
    release({ data: { user_id: 9, access: "GRANTED", sides: ["FAMILY"] } });
  });

  it.each([
    [new ApiError(403, {}), "لا تملك صلاحية إدارة تصاريح الاستعلام المدني."],
    [new ApiError(422, { errors: { sides: ["الاستعلام لهذه الجهة غير مفعّل، فلا يمكن المنح لها."] } }), "الاستعلام لهذه الجهة غير مفعّل، فلا يمكن المنح لها."],
    [new ApiError(429, {}), "طلبات كثيرة خلال وقت قصير. حاول مرة أخرى بعد قليل."],
  ])("shows a fixed Arabic message for a refusal (%#)", async (error, message) => {
    const user = userEvent.setup();
    put.mockRejectedValue(error);
    renderPage();
    await selectFamily(user);
    await user.click(screen.getByRole("radio", { name: /منح فردي صريح/ }));
    await user.click(screen.getByRole("checkbox", { name: /بوابة الأسرة/ }));
    const dialog = await confirm(user);
    expect((await findEl(() => dialog.querySelector("[data-dialog-error]"))).textContent).toBe(message);
  });

  it("refuses a stale state, reloads the current one and restarts the editor from it", async () => {
    const user = userEvent.setup();
    let state = familyState();
    mockApi({ "PER-000123": () => state });
    put.mockImplementation(async () => {
      // Another administrator revoked the account meanwhile.
      state = familyState({ access: "REVOKED", changed_by: "مدير آخر", changed_at: "2026-10-27T10:00:00+00:00" });
      throw new ApiError(409, { code: "ACCESS_CHANGED" });
    });
    renderPage();
    await selectFamily(user);
    await user.click(screen.getByRole("radio", { name: /منح فردي صريح/ }));
    await user.click(screen.getByRole("checkbox", { name: /بوابة الأسرة/ }));
    await confirm(user);

    expect((await findEl(() => document.querySelector('[data-access-notice="conflict"]'))).textContent).toContain("بواسطة مسؤول آخر");
    await waitFor(() => expect(document.querySelector('[data-selected-account] [data-access-state="REVOKED"]')).not.toBeNull());
    expect(document.querySelector("[data-last-change]")?.textContent).toContain("مدير آخر");
    expect(screen.getByRole("radio", { name: /إيقاف صريح/ })).toBeChecked();
    expect(saveButton()).toBeDisabled();
    expect(put).toHaveBeenCalledTimes(1);
  });
});

describe("history", () => {
  it("shows per-user changes only, and the selected account's by default", async () => {
    const user = userEvent.setup();
    renderPage();
    const history = await findEl(() => document.querySelector('[data-access-change="31"]'));
    expect(get).toHaveBeenCalledWith(`${BASE}/admin/changes?page=1&subject=USER`);
    const cells = Array.from(history.querySelectorAll("td")).map((td) => td.textContent);
    expect(cells.slice(0, 5)).toEqual(["رب أسرة تجريبي", "بدون تجاوز فردي", "منح فردي صريح", "—", "بوابة الأسرة"]);
    expect(cells[5]).toBe("مدير تجريبي");

    await selectFamily(user);
    await waitFor(() => expect(get).toHaveBeenCalledWith(`${BASE}/admin/changes?page=1&subject=USER&user_id=9`));
    await user.click(screen.getByRole("checkbox", { name: "الحساب المحدد فقط" }));
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === `${BASE}/admin/changes?page=1&subject=USER`).length).toBeGreaterThan(0));
  });
});
