import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import CoordinatorsPage from "@/app/(staff)/administration/coordinators/page";
import AdministrationPage from "@/app/(staff)/administration/page";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import { navItems } from "@/lib/navigation";
import type { Clan } from "@/lib/types/api/clan";
import type { CoordinatorAssignment, PersonCoordinatorState } from "@/lib/types/api/coordinator-admin";
import type { PersonSummary } from "@/lib/types/api/person";
import { renderWithClient } from "./helpers";

// CO-01B: the Staff «إدارة المنسقين» screen on the existing coordinator API.
// Synthetic data only — no real name, code or registry record.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/administration/coordinators" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const CODE = "PER-000901";
const STATE_PATH = `/api/v1/people/${CODE}/coordinator`;
const MANAGE = ["coordinator-scope.view", "coordinator-scope.manage", "person.view", "family.view", "clan.view"];

function staff(permissions: string[]): CurrentUser {
  return { name: "مسؤول تجريبي", email: "admin@example.test", role: "ADMINISTRATOR", role_label: "مسؤول", permissions };
}

function summary(code: string, name: string, head: boolean): PersonSummary {
  return {
    person_code: code,
    full_name: name,
    gender: "MALE",
    birth_date: "1980-01-01",
    life_status: "ALIVE",
    family: { family_code: "FAM-000901", is_household_head: head, relationship: head ? { code: "HEAD", name: "رب الأسرة" } : { code: "SON", name: "ابن" }, branch_name: "فرع تجريبي" },
  };
}

const PEOPLE = [summary(CODE, "رب أسرة تجريبي", true), summary("PER-000902", "ابن تجريبي", false)];

const branch = (code: string, name: string) => ({ id: `id-${code}`, code, name, sort_order: 1, is_active: true });
const CLANS: Clan[] = [
  {
    id: "clan-1",
    code: "CLN-01",
    name: "عشيرة تجريبية",
    is_active: true,
    branch_groups: [{ id: "grp-1", code: "GRP-01", name: "مجموعة تجريبية", display_name: "مجموعة تجريبية", sort_order: 1, is_active: true, branches: [branch("BR-01", "فرع أول"), branch("BR-02", "فرع ثانٍ")] }],
    ungrouped_branches: [branch("BR-03", "فرع مستقل")],
  },
  { id: "clan-2", code: "CLN-02", name: "عشيرة أخرى", is_active: true, branch_groups: [], ungrouped_branches: [branch("BR-09", "فرع العشيرة الأخرى")] },
];

function assignment(overrides: Partial<CoordinatorAssignment> = {}): CoordinatorAssignment {
  return {
    id: "00000000-0000-4000-8000-000000000101",
    scope_type: "BRANCH",
    clan: { code: "CLN-01", name: "عشيرة تجريبية" },
    branch_group: null,
    branch: { code: "BR-01", name: "فرع أول" },
    active: true,
    assigned_at: "2026-10-01T09:00:00+03:00",
    assigned_by: "مسؤول تجريبي",
    revoked_at: null,
    revoked_by: null,
    revoke_reason: null,
    ...overrides,
  };
}

const REVOKED = assignment({
  id: "00000000-0000-4000-8000-000000000102",
  branch: { code: "BR-02", name: "فرع ثانٍ" },
  active: false,
  revoked_at: "2026-10-05T09:00:00+03:00",
  revoked_by: "مدير تجريبي",
  revoke_reason: "SCOPE_CHANGED",
});

function state(overrides: Partial<PersonCoordinatorState> = {}): PersonCoordinatorState {
  return { person_code: CODE, has_family_account: true, coordinator: false, coordinator_space: false, assignments: [], ...overrides };
}

/** Serves people search, clans and the CURRENT coordinator state (mutable between requests). */
function renderScreen(initial: PersonCoordinatorState | Error | "pending", { permissions = MANAGE, people = PEOPLE } = {}) {
  const server: { state: PersonCoordinatorState | Error | "pending" } = { state: initial };
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path.startsWith("/api/v1/people?")) return { data: people, links: {}, meta: {} } as never;
    if (path === "/api/v1/reference/clans") return { data: CLANS } as never;
    if (path === STATE_PATH) {
      if (server.state === "pending") return new Promise<never>(() => {});
      if (server.state instanceof Error) throw server.state;
      return { data: server.state } as never;
    }
    throw new Error(`Unexpected GET: ${path}`);
  });
  const post = vi.spyOn(apiClient, "post");
  const user = userEvent.setup();
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <CoordinatorsPage />
    </AuthContext.Provider>
  );
  return { server, get, post, user, ...result };
}

type User = ReturnType<typeof userEvent.setup>;

async function searchAndSelect(user: User) {
  await user.type(screen.getByLabelText("البحث عن رب أسرة"), "تجريبي");
  await user.click(screen.getByRole("button", { name: "بحث" }));
  const results = await screen.findByRole("list", { name: "نتائج البحث" });
  await user.click(within(results).getByRole("button", { name: "اختيار" }));
}

async function choose(user: User, label: string, option: string) {
  await user.click(screen.getByRole("combobox", { name: label }));
  await user.click(await screen.findByRole("option", { name: option }));
}

const overview = () => document.querySelector("[data-coordinator-overview]") as HTMLElement;

// Several Radix Select interactions per test: allow more than the 5s default.
vi.setConfig({ testTimeout: 20_000 });

beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
});

describe("access", () => {
  it("is forbidden, and requests nothing, without coordinator-scope.manage", () => {
    const { get } = renderScreen(state(), { permissions: ["coordinator-scope.view", "person.view", "clan.view"] });

    expect(document.querySelector("[data-forbidden]")).toBeInTheDocument();
    expect(screen.queryByText("إدارة المنسقين")).not.toBeInTheDocument();
    expect(get).not.toHaveBeenCalled();
  });

  it("is forbidden to a family-side / coordinator-only permission set", () => {
    const { get } = renderScreen(state(), { permissions: ["coordinator-space.access", "coordinator-family.view-summary"] });

    expect(document.querySelector("[data-forbidden]")).toBeInTheDocument();
    expect(get).not.toHaveBeenCalled();
  });

  it("renders the screen with an empty selection for an authorized administrator", () => {
    renderScreen(state());

    expect(screen.getByRole("heading", { name: "إدارة المنسقين" })).toBeInTheDocument();
    expect(screen.getByText("اختر رب أسرة")).toBeInTheDocument();
  });

  it("is reachable from الإدارة only with coordinator-scope.manage", () => {
    const admin = navItems.find((item) => item.href === "/administration");
    expect(admin?.permissions).toContain("coordinator-scope.manage");

    const { unmount } = renderWithClient(
      <AuthContext.Provider value={authValue(staff(MANAGE))}>
        <AdministrationPage />
      </AuthContext.Provider>
    );
    expect(screen.getByRole("link", { name: /المنسقون/ })).toHaveAttribute("href", "/administration/coordinators");
    unmount();

    renderWithClient(
      <AuthContext.Provider value={authValue(staff(["clan.manage"]))}>
        <AdministrationPage />
      </AuthContext.Provider>
    );
    expect(screen.queryByRole("link", { name: /المنسقون/ })).not.toBeInTheDocument();
  });
});

describe("selection", () => {
  it("lists household heads only and loads the selected head's state", async () => {
    const { user, get } = renderScreen(state());

    await user.type(screen.getByLabelText("البحث عن رب أسرة"), "تجريبي");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const results = await screen.findByRole("list", { name: "نتائج البحث" });
    expect(within(results).getByText("رب أسرة تجريبي")).toBeInTheDocument();
    expect(within(results).queryByText("ابن تجريبي")).not.toBeInTheDocument();

    await user.click(within(results).getByRole("button", { name: "اختيار" }));
    await waitFor(() => expect(overview()).toBeInTheDocument());
    expect(get).toHaveBeenCalledWith(STATE_PATH);
    expect(within(overview()).getByText("غير منسق")).toBeInTheDocument();
  });

  it("shows an empty state when no household head matches", async () => {
    const { user } = renderScreen(state(), { people: [summary("PER-000902", "ابن تجريبي", false)] });

    await user.type(screen.getByLabelText("البحث عن رب أسرة"), "ابن");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    expect(await screen.findByText("لا يوجد أرباب أسر مطابقون")).toBeInTheDocument();
  });

  it("shows a loading state while the coordinator state loads", async () => {
    const { user } = renderScreen("pending");

    await searchAndSelect(user);
    expect(await screen.findByText("جارٍ تحميل حالة المنسق")).toBeInTheDocument();
  });

  it("explains a missing Family Portal account and offers no grant", async () => {
    const { user } = renderScreen(state({ has_family_account: false }));

    await searchAndSelect(user);
    expect(await screen.findByText("لا يوجد حساب بوابة أسرة")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "تعيين منسقًا" })).not.toBeInTheDocument();
  });

  it.each([
    [403, "لا تملك صلاحية إدارة المنسقين."],
    [404, "لم يُعثر على الشخص أو التعيين. حدّث الصفحة."],
    [401, "انتهت الجلسة. سجّل الدخول مرة أخرى."],
    [429, "محاولات كثيرة خلال وقت قصير. حاول بعد قليل."],
  ])("shows a safe message when the state request fails with %i", async (status, message) => {
    const { user } = renderScreen(new ApiError(status, { message: "raw server text" }));

    await searchAndSelect(user);
    expect(await screen.findByText(message)).toBeInTheDocument();
    expect(screen.queryByText("raw server text")).not.toBeInTheDocument();
  });
});

describe("granting the role", () => {
  it("asks for confirmation, then grants and shows the new state", async () => {
    const { user, post, server } = renderScreen(state());
    post.mockImplementation(async () => {
      server.state = state({ coordinator: true });
      return { data: server.state } as never;
    });

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: "تعيين منسقًا" }));
    const dialog = await screen.findByRole("dialog");
    expect(post).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole("button", { name: "تأكيد التعيين" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(STATE_PATH, {}));
    expect(await within(overview()).findByText("منسق")).toBeInTheDocument();
    expect(within(overview()).getByText("مساحة التنسيق غير مفعّلة")).toBeInTheDocument();
    expect(screen.getByText("أُسند دور المنسق. أضف الآن نطاقًا تنظيميًا.")).toBeInTheDocument();
  });

  it.each([
    [422, "NOT_ELIGIBLE", "لا يمكن تعيين منسق: صاحب الحساب ليس رب أسرة مؤهلًا لاستخدام بوابة الأسرة."],
    [409, "ALREADY_COORDINATOR", "هذا الحساب يحمل دور المنسق مسبقًا."],
  ])("shows the refusal (%i %s) in the dialog and re-reads the state", async (status, code, message) => {
    const { user, post, get } = renderScreen(state());
    post.mockRejectedValue(new ApiError(status, { message: "x", code }));

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: "تعيين منسقًا" }));
    const before = get.mock.calls.filter(([p]) => p === STATE_PATH).length;
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد التعيين" }));

    expect(await within(screen.getByRole("dialog")).findByText(message)).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === STATE_PATH).length).toBeGreaterThan(before));
  });
});

describe("scopes", () => {
  it("distinguishes active from revoked assignments with their history", async () => {
    const { user } = renderScreen(state({ coordinator: true, coordinator_space: true, assignments: [assignment(), REVOKED] }));

    await searchAndSelect(user);
    await waitFor(() => expect(document.querySelector('[data-assignment="active"]')).toBeInTheDocument());
    const active = document.querySelector('[data-assignment="active"]') as HTMLElement;
    const revoked = document.querySelector('[data-assignment="revoked"]') as HTMLElement;
    expect(within(active).getByText("نشط")).toBeInTheDocument();
    expect(within(active).getByRole("button", { name: /إلغاء النطاق/ })).toBeInTheDocument();
    expect(within(revoked).getByText("ملغى")).toBeInTheDocument();
    expect(within(revoked).getByText(/تغيّر النطاق/)).toBeInTheDocument();
    expect(within(revoked).queryByRole("button")).not.toBeInTheDocument();
    expect(within(overview()).getByText("مساحة التنسيق مفعّلة")).toBeInTheDocument();
  });

  it("shows an empty state for a coordinator without scopes", async () => {
    const { user } = renderScreen(state({ coordinator: true }));

    await searchAndSelect(user);
    expect(await screen.findByText("لا توجد نطاقات")).toBeInTheDocument();
    expect(screen.getByText("أضف نطاقًا تنظيميًا واحدًا على الأقل لتفعيل مساحة التنسيق.")).toBeInTheDocument();
  });

  it("offers no scope form to a person who is not a coordinator", async () => {
    const { user } = renderScreen(state());

    await searchAndSelect(user);
    await screen.findByRole("button", { name: "تعيين منسقًا" });
    expect(document.querySelector("[data-coordinator-scope-form]")).not.toBeInTheDocument();
  });

  it("assigns several branches one after another, never offering a held one twice", async () => {
    const { user, post, server } = renderScreen(state({ coordinator: true, assignments: [assignment()] }));
    let n = 200;
    post.mockImplementation(async (_path: string, body: unknown) => {
      const b = body as { branch: string };
      const current = server.state as PersonCoordinatorState;
      server.state = { ...current, coordinator_space: true, assignments: [assignment({ id: `00000000-0000-4000-8000-000000000${n++}`, branch: { code: b.branch, name: b.branch } }), ...current.assignments] };
      return { data: server.state } as never;
    });

    await searchAndSelect(user);
    await screen.findByRole("combobox", { name: "العشيرة" });
    await choose(user, "العشيرة", "عشيرة تجريبية");

    await user.click(screen.getByRole("combobox", { name: "الفرع" }));
    expect(await screen.findByRole("option", { name: /فرع أول/ })).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("option", { name: "فرع مستقل" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "فرع العشيرة الأخرى" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("option", { name: "فرع ثانٍ" }));
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${STATE_PATH}/scopes`, { scope_type: "BRANCH", clan: "CLN-01", branch: "BR-02" }));

    // A branch without a group is reachable through its own filter.
    await choose(user, "تصفية حسب المجموعة", "فروع بلا مجموعة");
    await choose(user, "الفرع", "فرع مستقل");
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    await waitFor(() => expect(post).toHaveBeenLastCalledWith(`${STATE_PATH}/scopes`, { scope_type: "BRANCH", clan: "CLN-01", branch: "BR-03" }));
    await waitFor(() => expect(document.querySelectorAll('[data-assignment="active"]')).toHaveLength(3));
  });

  it("assigns a branch group and a whole clan by their codes", async () => {
    const { user, post } = renderScreen(state({ coordinator: true }));
    post.mockResolvedValue({ data: state({ coordinator: true }) } as never);

    await searchAndSelect(user);
    await screen.findByRole("combobox", { name: "العشيرة" });
    await choose(user, "مستوى النطاق", "مجموعة فروع");
    await choose(user, "العشيرة", "عشيرة تجريبية");
    await choose(user, "مجموعة الفروع", "مجموعة تجريبية");
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${STATE_PATH}/scopes`, { scope_type: "BRANCH_GROUP", clan: "CLN-01", branch_group: "GRP-01" }));

    await choose(user, "مستوى النطاق", "العشيرة كاملة");
    await choose(user, "العشيرة", "عشيرة أخرى");
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    await waitFor(() => expect(post).toHaveBeenLastCalledWith(`${STATE_PATH}/scopes`, { scope_type: "CLAN", clan: "CLN-02" }));
  });

  it("shows a server refusal of the scope (SCOPE_EXISTS)", async () => {
    const { user, post } = renderScreen(state({ coordinator: true }));
    post.mockRejectedValue(new ApiError(409, { message: "x", code: "SCOPE_EXISTS" }));

    await searchAndSelect(user);
    await screen.findByRole("combobox", { name: "العشيرة" });
    await choose(user, "العشيرة", "عشيرة تجريبية");
    await choose(user, "الفرع", "فرع ثانٍ");
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    expect(await screen.findByText("هذا النطاق معيّن لهذا المنسق مسبقًا.")).toBeInTheDocument();
  });

  it("shows the first validation error of a 422", async () => {
    const { user, post } = renderScreen(state({ coordinator: true }));
    post.mockRejectedValue(new ApiError(422, { message: "x", errors: { branch: ["الفرع لا ينتمي إلى العشيرة المحددة."] } }));

    await searchAndSelect(user);
    await screen.findByRole("combobox", { name: "العشيرة" });
    await choose(user, "العشيرة", "عشيرة تجريبية");
    await choose(user, "الفرع", "فرع ثانٍ");
    await user.click(screen.getByRole("button", { name: "إضافة النطاق" }));
    expect(await screen.findByText("الفرع لا ينتمي إلى العشيرة المحددة.")).toBeInTheDocument();
  });
});

describe("revocation", () => {
  it("revokes one scope only after confirming with a reason", async () => {
    const { user, post, server } = renderScreen(state({ coordinator: true, coordinator_space: true, assignments: [assignment()] }));
    post.mockImplementation(async () => {
      const revoked = assignment({ active: false, revoked_at: "2026-10-12T09:00:00+03:00", revoked_by: "مسؤول تجريبي", revoke_reason: "ADMINISTRATIVE" });
      server.state = state({ coordinator: true, assignments: [revoked] });
      return { data: revoked } as never;
    });

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: /إلغاء النطاق/ }));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "تأكيد الإلغاء" });
    expect(confirm).toBeDisabled();
    await choose(user, "سبب الإلغاء", "قرار إداري");
    await user.click(confirm);

    await waitFor(() => expect(post).toHaveBeenCalledWith("/api/v1/coordinator-scopes/00000000-0000-4000-8000-000000000101/revoke", { reason: "ADMINISTRATIVE" }));
    await waitFor(() => expect(document.querySelector('[data-assignment="revoked"]')).toBeInTheDocument());
    expect(document.querySelector('[data-assignment="active"]')).not.toBeInTheDocument();
  });

  it("shows ALREADY_REVOKED and refreshes the list", async () => {
    const { user, post, get } = renderScreen(state({ coordinator: true, assignments: [assignment()] }));
    post.mockRejectedValue(new ApiError(409, { message: "x", code: "ALREADY_REVOKED" }));

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: /إلغاء النطاق/ }));
    await choose(user, "سبب الإلغاء", "تغيّر النطاق");
    const before = get.mock.calls.filter(([p]) => p === STATE_PATH).length;
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await screen.findByText("هذا التعيين ملغى مسبقًا.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === STATE_PATH).length).toBeGreaterThan(before));
  });

  it("revokes the role after a warning and a reason", async () => {
    const { user, post, server } = renderScreen(state({ coordinator: true, coordinator_space: true, assignments: [assignment()] }));
    post.mockImplementation(async () => {
      server.state = state({ assignments: [assignment({ active: false, revoked_at: "2026-10-12T09:00:00+03:00", revoke_reason: "ROLE_REMOVED" })] });
      return { data: server.state } as never;
    });

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: "إلغاء دور المنسق" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/ستُغلق مساحة التنسيق لهذا الحساب فورًا/)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" })).toBeDisabled();
    await choose(user, "سبب الإلغاء", "لم يعد مؤهلًا");
    await user.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(`${STATE_PATH}/revoke`, { reason: "NO_LONGER_ELIGIBLE" }));
    expect(await within(overview()).findByText("غير منسق")).toBeInTheDocument();
    const revoked = document.querySelector('[data-assignment="revoked"]') as HTMLElement;
    expect(within(revoked).getByText(/إلغاء دور المنسق/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تعيين منسقًا" })).toBeInTheDocument();
  });

  it("does not post when the role revocation is cancelled", async () => {
    const { user, post } = renderScreen(state({ coordinator: true, assignments: [assignment()] }));

    await searchAndSelect(user);
    await user.click(await screen.findByRole("button", { name: "إلغاء دور المنسق" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تراجع" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(post).not.toHaveBeenCalled();
  });
});
