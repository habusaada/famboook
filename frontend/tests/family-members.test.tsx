import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyMembers } from "@/components/family/members/family-members";
import { UNAVAILABLE_MEMBER, ageText } from "@/components/family/members/member-card";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_MEMBERS_QUERY_KEY, type FamilyMember } from "@/lib/api/family-household";
import { browserStorageDump, familyGet, familyMember, familyMembers, renderWithClient } from "./helpers";

// PWA-3A Step 3: /family/members on GET /api/v1/family/household/members.
// Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/members" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const MEMBERS = "/api/v1/family/household/members";

function renderMembers(members?: FamilyMember[] | Error | (() => Promise<unknown>)) {
  const answer = members === undefined ? familyMembers() : Array.isArray(members) ? familyMembers(members) : members;
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ members: answer }));
  const result = renderWithClient(
    <FamilyGate>
      <FamilyMembers />
    </FamilyGate>
  );

  return { get, ...result };
}

const list = () => screen.findByRole("list", { name: "أفراد الأسرة" });
const rows = async () => within(await list()).getAllByRole("listitem");

/** A birth date exactly `years` years and one day ago (age = years). */
function bornYearsAgo(years: number): string {
  const d = new Date();
  d.setFullYear(d.getFullYear() - years);
  d.setDate(d.getDate() - 1);
  return d.toISOString().slice(0, 10);
}

const HEAD = familyMember({
  full_name: "سالم الاختبار", relationship: { code: "HEAD", name: "رب الأسرة" }, is_household_head: true, birth_date: "1980-01-01",
});

beforeEach(() => {
  router.replace.mockReset();
  router.push.mockReset();
  location.pathname = "/family/members";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the members list", () => {
  it("reads GET /api/v1/family/household/members with no identifier", async () => {
    const { get } = renderMembers();
    await list();

    const paths = get.mock.calls.map(([path]) => String(path));
    expect(paths.filter((p) => p.startsWith(MEMBERS))).toEqual([MEMBERS]);
    expect(paths.join(" ")).not.toMatch(/family_id|person_id|membership_id|\?/);
  });

  it("shows the page title, the way back, the family code (LTR) and the count of rows", async () => {
    const { container } = renderMembers([
      HEAD,
      familyMember({ full_name: "أ" }),
      familyMember({ available: false, full_name: null, gender: null, birth_date: null, life_status: null }),
    ]);
    await list();

    expect(screen.getByRole("heading", { level: 1, name: "أفراد الأسرة" })).toBeInTheDocument();
    expect(screen.getAllByRole("heading", { level: 1 })).toHaveLength(1);
    const back = container.querySelector("[data-members-back]");
    // Members sit under أسرتي: the way back leads there.
    expect(back).toHaveTextContent("أسرتي");
    expect(back).toHaveAttribute("href", "/family/household");
    expect(screen.getByText("FAM-000123")).toHaveAttribute("dir", "ltr");
    // members.length, the unavailable row included; no second count requested.
    expect(container.querySelector("[data-members-count]")?.textContent).toBe("أفراد الأسرة المسجلون (3)");
    // One metadata row under the title: the count and the code.
    const meta = container.querySelector("header [data-members-meta]");
    expect(meta).toContainElement(container.querySelector("[data-family-code]") as HTMLElement);
    expect(screen.queryByText(/المسجّلون بالتفصيل/)).not.toBeInTheDocument();
  });

  it("renders the rows exactly in the server's order", async () => {
    const names = ["سالم الاختبار", "ياسمين", "أحمد", "باسل", "ثامر"];
    renderMembers([HEAD, ...names.slice(1).map((full_name) => familyMember({ full_name }))]);

    const items = await rows();
    expect(items.map((li) => li.querySelector("bdi")?.textContent)).toEqual(names);
  });

  it("marks the household head as رب الأسرة and أنت", async () => {
    renderMembers([HEAD, familyMember()]);

    const [head, son] = await rows();
    expect(within(head).getByText("رب الأسرة")).toBeInTheDocument();
    expect(within(head).getByText("أنت")).toBeInTheDocument();
    expect(within(son).queryByText("أنت")).not.toBeInTheDocument();
    expect(within(son).queryByText("رب الأسرة")).not.toBeInTheDocument();
  });

  it.each([
    [{ code: "SPOUSE", name: "زوج/زوجة" }, "FEMALE", "زوجة"],
    [{ code: "SPOUSE", name: "زوج/زوجة" }, "MALE", "زوج"],
    [{ code: "SON", name: "ابن" }, "MALE", "ابن"],
    [{ code: "DAUGHTER", name: "ابنة" }, "FEMALE", "ابنة"],
    [{ code: "FATHER", name: "أب" }, "MALE", "أب"],
    [{ code: "MOTHER", name: "أم" }, "FEMALE", "أم"],
    [null, "MALE", "غير محدد"],
  ] as const)("labels %j (%s) as %s", async (relationship, gender, label) => {
    renderMembers([HEAD, familyMember({ relationship, gender })]);

    const [, member] = await rows();
    expect(member.querySelector("[data-member-relationship]")).toHaveTextContent(label);
  });

  it("shows an ALIVE member's age and birth date as one compact line: «N سنة · day month year»", async () => {
    const born = bornYearsAgo(37);
    renderMembers([HEAD, familyMember({ birth_date: born })]);

    const [, member] = await rows();
    const line = member.querySelector("[data-member-dates]") as HTMLElement;
    expect(line.querySelector("[data-member-age]")?.textContent).toBe("37 سنة");
    expect(line.textContent).toMatch(/^37 سنة·\d{1,2} [\u0600-\u06FF]+ \d{4}$/);
    expect(line.textContent).not.toMatch(/العمر|تاريخ الميلاد|\d{4}-\d{2}-\d{2}/);
    expect(member.querySelector("[data-member-status]")).toBeNull();
  });

  it("writes the birth date in words with Latin digits", async () => {
    renderMembers([HEAD, familyMember({ life_status: "UNKNOWN", birth_date: "1989-01-28" })]);

    const [, member] = await rows();
    expect(member.querySelector("[data-member-birth-date]")?.textContent).toBe("28 يناير 1989");
  });

  it.each([
    [0, "أقل من سنة"],
    [1, "سنة واحدة"],
    [2, "سنتان"],
    [3, "3 سنوات"],
    [10, "10 سنوات"],
    [11, "11 سنة"],
    [37, "37 سنة"],
  ])("counts %i years as «%s»", (years, text) => {
    expect(ageText(bornYearsAgo(years))).toBe(text);
  });

  it("manufactures no date when the birth date is not known", async () => {
    renderMembers([HEAD, familyMember({ birth_date: null })]);

    const [, member] = await rows();
    expect(member.querySelector("[data-member-age]")).toBeNull();
    expect(member.querySelector("[data-member-birth-date]")?.textContent).toBe("تاريخ الميلاد غير معروف");
    expect(member.querySelector("[data-member-dates]")?.textContent).not.toMatch(/\d/);
  });

  it("shows UNKNOWN as a neutral «الحالة غير مؤكدة» status with an icon", async () => {
    renderMembers([HEAD, familyMember({ life_status: "UNKNOWN" })]);

    const [, member] = await rows();
    const status = member.querySelector('[data-member-status="unknown"]');
    expect(status).toHaveTextContent("الحالة غير مؤكدة");
    expect(status?.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    // Beside the relationship, as part of the member's information.
    expect(status?.parentElement).toContainElement(member.querySelector("[data-member-relationship]") as HTMLElement);
  });

  it.each([
    ["MALE", "متوفى"],
    ["FEMALE", "متوفاة"],
    [null, "متوفى"],
  ] as const)("shows a deceased %s member as «%s» with an icon and no current age", async (gender, label) => {
    renderMembers([HEAD, familyMember({ life_status: "DECEASED", gender, birth_date: "1950-06-01" })]);

    const [, member] = await rows();
    const status = member.querySelector('[data-member-status="deceased"]');
    expect(status?.textContent).toBe(label);
    expect(status?.querySelector("svg")).not.toBeNull();
    expect(status?.parentElement).toContainElement(member.querySelector("[data-member-relationship]") as HTMLElement);
    expect(member.querySelector("[data-member-age]")).toBeNull();
    expect(member.textContent).not.toMatch(/سنة|سنوات|سنتان|العمر/);
    expect(member.querySelector("[data-member-dates]")?.textContent).toBe("1 يونيو 1950");
  });
});

describe("an unavailable member", () => {
  const unavailable = (relationship: FamilyMember["relationship"]) =>
    familyMember({ available: false, full_name: null, relationship, gender: null, birth_date: null, life_status: null });

  it("is a placeholder row that keeps the membership relationship", async () => {
    renderMembers([HEAD, unavailable({ code: "DAUGHTER", name: "ابنة" }), unavailable(null)]);

    const [, withRelationship, without] = await rows();
    expect(withRelationship).toHaveTextContent(UNAVAILABLE_MEMBER);
    expect(withRelationship.querySelector("[data-member-relationship]")).toHaveTextContent("ابنة");
    expect(without.querySelector("[data-member-relationship]")).toHaveTextContent("غير محدد");
  });

  it("shows no name, gender, birth date, age or life status", async () => {
    renderMembers([HEAD, unavailable({ code: "SPOUSE", name: "زوج/زوجة" })]);

    const [, row] = await rows();
    expect(row.querySelector("bdi")).toBeNull();
    for (const selector of ["[data-member-age]", "[data-member-birth-date]", "[data-member-status]", "[data-member-head]"]) {
      expect(row.querySelector(selector)).toBeNull();
    }
    // A spouse with no gender is never shown as زوج or زوجة.
    expect(row.querySelector("[data-member-relationship]")).toHaveTextContent("زوج/زوجة");
    expect(row.textContent).not.toMatch(/null|undefined|العمر|تاريخ الميلاد|متوف|مؤكدة/);
  });
});

describe("loading, empty, errors and access", () => {
  it("shows a busy skeleton while the members load", async () => {
    let release: (value: unknown) => void = () => undefined;
    const { container } = renderMembers(() => new Promise((resolve) => (release = resolve)));

    expect(await screen.findByRole("status")).toHaveTextContent("جارٍ تحميل أفراد الأسرة");
    expect(container.querySelector("[data-members]")).toHaveAttribute("aria-busy", "true");
    expect(container.querySelector("[data-members-loading]")).not.toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "أفراد الأسرة" })).toBeInTheDocument();

    await act(async () => release({ data: familyMembers() }));
    expect(await list()).toBeInTheDocument();
    expect(container.querySelector("[data-members]")).toHaveAttribute("aria-busy", "false");
    expect(container.querySelector("[data-members-loading]")).toBeNull();
  });

  it("has a defensive empty state and invents no member", async () => {
    const { container } = renderMembers([]);

    expect(await screen.findByText("لا يوجد أفراد مسجّلون لهذه الأسرة حاليًا.")).toBeInTheDocument();
    expect(container.querySelector("[data-members-count]")?.textContent).toBe("أفراد الأسرة المسجلون (0)");
    expect(screen.queryByRole("list", { name: "أفراد الأسرة" })).not.toBeInTheDocument();
    expect(container.querySelector("[data-member-row]")).toBeNull();
  });

  it("shows a transient failure inline and retries", async () => {
    let calls = 0;
    renderMembers(async () => {
      calls++;
      if (calls === 1) throw new ApiError(500, null);
      return { data: familyMembers() };
    });

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل أفراد الأسرة");
    expect(screen.getByRole("navigation", { name: "التنقل الرئيسي" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(await list()).toBeInTheDocument();
    expect(calls).toBe(2);
  });

  it("treats a network failure inline too, not as an access failure", async () => {
    renderMembers(new TypeError("Failed to fetch"));

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل أفراد الأسرة");
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();
  });

  it.each([
    ["the family context boundary", { message: "بيانات الأسرة غير متاحة لهذا الحساب.", code: "FAMILY_CONTEXT_UNAVAILABLE" }],
    ["the permission check", { message: "This action is unauthorized." }],
    ["an empty body", null],
  ])("treats ANY 403 (%s) as family data unavailable", async (_label, body) => {
    renderMembers(new ApiError(403, body));

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("تعذّر تحميل أفراد الأسرة")).not.toBeInTheDocument();
    expect(screen.queryByText(/This action is unauthorized|FAMILY_CONTEXT_UNAVAILABLE/)).not.toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("returns an expired session (401) to the Family login", async () => {
    renderMembers(new ApiError(401, { message: "Unauthenticated." }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByText("تعذّر تحميل أفراد الأسرة")).not.toBeInTheDocument();
  });
});

describe("navigation and privacy", () => {
  it("marks أسرتي as the current page and keeps the rest disabled", async () => {
    renderMembers();
    await list();

    const nav = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    const members = within(nav).getByRole("link", { name: "أسرتي" });
    expect(members).toHaveAttribute("href", "/family/household");
    expect(members).toHaveAttribute("aria-current", "page");
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).not.toHaveAttribute("aria-current");
    expect(within(nav).getAllByRole("link")).toHaveLength(2);
    for (const label of ["طلباتي", "حسابي", "إجراء جديد (قريبًا)"]) {
      expect(within(nav).getByRole("button", { name: label })).toBeDisabled();
    }
  });

  it("renders no identifier or contact data, even if a response carried some", async () => {
    // Defensive: extra fields a server must never send are still never rendered.
    const leaky = { ...familyMember({ full_name: "فرد" }), national_id: "807766554", mobile: "0597766554", person_code: "PER-000777", id: 4242 };
    renderMembers([HEAD, leaky as FamilyMember]);
    await list();

    for (const secret of ["807766554", "0597766554", "PER-000777", "4242", "*****"]) {
      expect(document.body.textContent).not.toContain(secret);
    }
  });

  it("keeps the members in memory only: nothing in browser storage", async () => {
    const { client } = renderMembers();
    await list();

    expect(client.getQueryData(FAMILY_MEMBERS_QUERY_KEY)).toEqual(familyMembers());
    const dump = browserStorageDump();
    for (const value of ["سالم الاختبار", "فرد الاختبار", "FAM-000123", "2010-01-01", "members"]) {
      expect(dump).not.toContain(value);
    }
  });
});

describe("the way to «بياناتي الشخصية» (PWA-3B.1)", () => {
  it("is offered on the head's own card only", async () => {
    renderMembers([
      HEAD,
      familyMember({ full_name: "زوجة الاختبار", relationship: { code: "SPOUSE", name: "زوج/زوجة" }, gender: "FEMALE" }),
      familyMember({ full_name: "ابن الاختبار" }),
    ]);
    const [head, ...others] = await rows();

    const link = within(head).getByRole("link", { name: "بياناتي الشخصية" });
    expect(link).toHaveAttribute("href", "/family/account/me");
    for (const row of others) {
      expect(within(row).queryByRole("link")).not.toBeInTheDocument();
    }
    expect(screen.getAllByRole("link", { name: "بياناتي الشخصية" })).toHaveLength(1);
  });

  it("is never offered on an unavailable member's placeholder", async () => {
    renderMembers([HEAD, familyMember({ available: false, full_name: null, gender: null, birth_date: null, life_status: null })]);
    const [, placeholder] = await rows();

    expect(within(placeholder).queryByRole("link")).not.toBeInTheDocument();
  });
});
