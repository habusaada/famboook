import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { CoordinatorFamilyDirectory } from "@/components/family/coordinator/coordinator-family-directory";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CoordinatorDirectory, CoordinatorMember } from "@/lib/api/coordinator";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import { browserStorageDump, familyUser, renderWithClient } from "./helpers";

// CO-01C (docs/06 AUTH-ADR-092): the Coordinator family details and members
// directory. Synthetic values only — no real name, National ID or number.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/family/coordinator/families/FAM-100001" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const CODE = "FAM-100001";
const DIRECTORY_PATH = `/api/v1/family/coordinator/families/${CODE}/members`;
const SUMMARY_PATH = `/api/v1/family/coordinator/families/${CODE}`;
const FULL_NID = "804455667";
const FULL_MOBILE = "0598877112";

const ref = (n: number) => n.toString(16).padStart(64, "0");

function member(n: number, overrides: Partial<CoordinatorMember> = {}): CoordinatorMember {
  return {
    member_ref: ref(n),
    available: true,
    full_name: `فرد تجريبي ${n}`,
    relationship: { code: "SON", name: "ابن" },
    is_household_head: false,
    gender: "MALE",
    age: 12,
    marital_status: "SINGLE",
    life_status: "ALIVE",
    national_id_masked: "*****5667",
    mobile_masked: "05*****112",
    ...overrides,
  };
}

const HEAD = member(1, { full_name: "رب أسرة تجريبي", relationship: { code: "HEAD", name: "رب الأسرة" }, is_household_head: true, age: 45, marital_status: "MARRIED" });

function directory(overrides: Partial<CoordinatorDirectory> = {}): CoordinatorDirectory {
  return {
    family: {
      family_code: CODE,
      clan_name: "عشيرة تجريبية",
      branch_group_name: "مجموعة تجريبية",
      branch_name: "فرع تجريبي",
      head_name: "رب أسرة تجريبي",
      registered_member_count: 3,
      declared_household_size: 7,
      declared_at: "2026-09-01",
    },
    members: [HEAD, member(2), member(3, { full_name: "متوفى تجريبي", life_status: "DECEASED", age: null })],
    can_reveal: true,
    ...overrides,
  };
}

function renderDirectory(reply: CoordinatorDirectory | Error | "pending") {
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === DIRECTORY_PATH) {
      if (reply === "pending") return new Promise<never>(() => {});
      if (reply instanceof Error) throw reply;
      return { data: reply } as never;
    }
    if (path === SUMMARY_PATH) {
      return { data: { family_code: CODE, clan_name: "عشيرة تجريبية", branch_group_name: null, branch_name: "فرع تجريبي", head_name: "رب أسرة تجريبي", active_member_count: 3 } } as never;
    }
    throw new Error(`Unexpected GET: ${path}`);
  });
  const post = vi.spyOn(apiClient, "post");
  const result = renderWithClient(<CoordinatorFamilyDirectory code={CODE} />);
  return { get, post, user: userEvent.setup(), ...result };
}

const table = () => screen.findByRole("table");

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("family details", () => {
  it("shows a loading state first", () => {
    renderDirectory("pending");
    expect(screen.getByLabelText("جارٍ تحميل بيانات الأسرة")).toBeInTheDocument();
  });

  it("shows the details with registered and declared counts kept distinct, marked as Coordinator Space", async () => {
    renderDirectory(directory());

    const details = (await screen.findByText("الأفراد المسجّلون")).closest("[data-coordinator-details]") as HTMLElement;
    expect(within(details).getByText(CODE)).toBeInTheDocument();
    expect(within(details).getByText("عشيرة تجريبية")).toBeInTheDocument();
    expect(within(details).getByText("مجموعة تجريبية")).toBeInTheDocument();
    expect(within(details).getByText("فرع تجريبي")).toBeInTheDocument();
    expect(within(details).getByText("الأفراد المسجّلون")).toBeInTheDocument();
    expect(details.querySelector("[data-registered-count]")).toHaveTextContent("3");
    expect(within(details).getByText("حجم الأسرة المُعلن")).toBeInTheDocument();
    expect(details.querySelector("[data-declared-size]")).toHaveTextContent("7");
    expect(screen.getByText(/مساحة التنسيق · أسرة ضمن نطاقك/)).toBeInTheDocument();
  });

  it("says when no household size was declared", async () => {
    renderDirectory(directory({ family: { ...directory().family, declared_household_size: null, declared_at: null } }));
    expect(await screen.findByText("غير مُعلن")).toBeInTheDocument();
  });

  it("treats an out-of-scope or unknown family as unavailable", async () => {
    renderDirectory(new ApiError(404, { code: "FAMILY_NOT_FOUND" }));
    expect(await screen.findByText("الأسرة غير متاحة.")).toBeInTheDocument();
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("falls back to the summary without the directory permission", async () => {
    const { get } = renderDirectory(new ApiError(403, { message: "forbidden" }));
    expect(await screen.findByText("ملخص الأسرة")).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(SUMMARY_PATH);
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("shows a connection error for other failures", async () => {
    renderDirectory(new ApiError(500, {}));
    expect(await screen.findByText("تعذّر الاتصال بالخادم.")).toBeInTheDocument();
  });
});

describe("members directory", () => {
  it("renders every member in a desktop table and in mobile cards", async () => {
    renderDirectory(directory());

    const grid = await table();
    expect(grid.parentElement).toHaveClass("hidden", "md:block");
    expect(within(grid).getAllByRole("row")).toHaveLength(4);
    const cards = screen.getByRole("list", { name: "أفراد الأسرة" });
    expect(cards).toHaveClass("md:hidden");
    expect(within(cards).getAllByRole("listitem")).toHaveLength(3);

    const head = within(grid).getAllByRole("row")[1];
    expect(within(head).getByText("رب أسرة تجريبي")).toBeInTheDocument();
    expect(within(head).getByText("رب الأسرة", { selector: "span.rounded-md" })).toBeInTheDocument();
    expect(within(head).getByText("45 سنة")).toBeInTheDocument();
    expect(within(head).getByText("ذكر")).toBeInTheDocument();
    expect(within(head).getByText("متزوج/ة")).toBeInTheDocument();
    expect(within(head).getByText("على قيد الحياة")).toBeInTheDocument();
    const deceased = within(grid).getAllByRole("row")[3];
    expect(within(deceased).getByText("متوفى")).toBeInTheDocument();
  });

  it("shows identifiers masked only — never a full value — until a reveal", async () => {
    renderDirectory(directory());

    const grid = await table();
    expect(within(grid).getAllByText("*****5667").length).toBeGreaterThan(0);
    expect(within(grid).getAllByText("05*****112").length).toBeGreaterThan(0);
    expect(document.body.textContent).not.toContain(FULL_NID);
    expect(document.body.textContent).not.toContain(FULL_MOBILE);
    expect(screen.getByText(/كل إظهار لقيمة كاملة يُسجَّل باسمك/)).toBeInTheDocument();
  });

  it("reveals ONE field of ONE member on request, and hides it again", async () => {
    const { post, user } = renderDirectory(directory());
    post.mockResolvedValue({ data: { field: "NATIONAL_ID", value: FULL_NID } } as never);

    const grid = await table();
    await user.click(within(grid).getByRole("button", { name: "إظهار رقم الهوية — فرد تجريبي 2" }));

    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${DIRECTORY_PATH}/${ref(2)}/reveal`, { field: "NATIONAL_ID" });
    expect(await within(grid).findByText(FULL_NID)).toBeInTheDocument();
    // Only that member's National ID: the mobile and the other members stay masked.
    expect(within(grid).getAllByText(FULL_NID)).toHaveLength(1);
    expect(document.body.textContent).not.toContain(FULL_MOBILE);
    expect(browserStorageDump()).not.toContain(FULL_NID);

    await user.click(within(grid).getByRole("button", { name: "إخفاء رقم الهوية — فرد تجريبي 2" }));
    expect(document.body.textContent).not.toContain(FULL_NID);
  });

  it("reveals the mobile through its own control", async () => {
    const { post, user } = renderDirectory(directory());
    post.mockResolvedValue({ data: { field: "MOBILE", value: FULL_MOBILE } } as never);

    const grid = await table();
    await user.click(within(grid).getByRole("button", { name: "إظهار رقم الجوال — رب أسرة تجريبي" }));

    expect(post).toHaveBeenCalledWith(`${DIRECTORY_PATH}/${ref(1)}/reveal`, { field: "MOBILE" });
    expect(await within(grid).findByText(FULL_MOBILE)).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(FULL_NID);
  });

  it("offers no reveal control without the sensitive permission", async () => {
    const { post } = renderDirectory(directory({ can_reveal: false }));

    const grid = await table();
    expect(within(grid).queryByRole("button", { name: /إظهار/ })).not.toBeInTheDocument();
    expect(within(grid).getAllByText("*****5667").length).toBeGreaterThan(0);
    expect(screen.queryByText(/كل إظهار لقيمة كاملة/)).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it.each([
    [403, "لا تملك صلاحية إظهار هذه القيمة."],
    [404, "هذه القيمة غير متاحة."],
    [429, "طلبات إظهار كثيرة. حاول مجددًا بعد قليل."],
    [500, "تعذّر إظهار القيمة. حاول مجددًا."],
  ])("explains a refused reveal (%i) without touching the own household context", async (status, message) => {
    const { post, user, client } = renderDirectory(directory());
    const me = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true });
    client.setQueryData(FAMILY_ME_QUERY_KEY, me);
    post.mockRejectedValue(new ApiError(status, { message: "raw server text" }));

    const grid = await table();
    await user.click(within(grid).getByRole("button", { name: "إظهار رقم الهوية — فرد تجريبي 2" }));

    expect(await within(grid).findByText(message)).toBeInTheDocument();
    expect(screen.queryByText("raw server text")).not.toBeInTheDocument();
    expect(client.getQueryData(FAMILY_ME_QUERY_KEY)).toEqual(me);
  });

  it("shows an unavailable member as a placeholder with nothing to reveal", async () => {
    renderDirectory(directory({ members: [HEAD, member(4, { available: false, full_name: null, gender: null, age: null, marital_status: null, life_status: null, national_id_masked: null, mobile_masked: null })] }));

    const grid = await table();
    const row = within(grid).getAllByRole("row")[2];
    expect(within(row).getByText("بيانات هذا الفرد غير متاحة")).toBeInTheDocument();
    expect(within(row).queryByRole("button")).not.toBeInTheDocument();
  });

  it("searches by name and says when nothing matches", async () => {
    const { user } = renderDirectory(directory());

    const grid = await table();
    await user.type(screen.getByLabelText("بحث باسم الفرد"), "متوفى");
    expect(within(grid).getAllByRole("row")).toHaveLength(2);
    expect(within(grid).getByText("متوفى تجريبي")).toBeInTheDocument();

    await user.clear(screen.getByLabelText("بحث باسم الفرد"));
    await user.type(screen.getByLabelText("بحث باسم الفرد"), "لا أحد");
    expect(await screen.findByText("لا يوجد أفراد مطابقون.")).toBeInTheDocument();
  });

  it("paginates a large household", async () => {
    const many = Array.from({ length: 12 }, (_, i) => member(i + 10));
    const { user } = renderDirectory(directory({ members: many }));

    const grid = await table();
    expect(within(grid).getAllByRole("row")).toHaveLength(11);
    expect(screen.getByText("صفحة 1 من 2")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "التالي" }));
    expect(within(await table()).getAllByRole("row")).toHaveLength(3);
    expect(screen.getByText("صفحة 2 من 2")).toBeInTheDocument();
  });

  it("shows an empty state for a family without registered members", async () => {
    renderDirectory(directory({ members: [], family: { ...directory().family, registered_member_count: 0 } }));
    expect(await screen.findByText("لا يوجد أفراد مسجّلون في هذه الأسرة.")).toBeInTheDocument();
    expect(screen.queryByLabelText("بحث باسم الفرد")).not.toBeInTheDocument();
  });
});
