import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyDeathReportForm } from "@/components/family/requests/family-death-report-form";
import { ApiError, apiClient } from "@/lib/api/client";
import { canStartFamilyRequest, familyRequestFormFor, type FamilyChangeRequestTypes } from "@/lib/api/family-change-requests";
import type { FamilyMember } from "@/lib/api/family-household";
import { browserStorageDump, renderWithClient } from "./helpers";

// DEATH_REPORT: the household head picks an eligible member (never the head,
// never someone already deceased) and gives the date of death or states it
// is unknown. The server decides availability and validates. Synthetic data.

const router = { push: vi.fn(), replace: vi.fn() };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/family/requests/new/death-report",
  useSearchParams: () => new URLSearchParams(),
}));

beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
});

const NEW_ID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const REF_HEAD = "a".repeat(64);
const REF_GRANDMA = "b".repeat(64);
const REF_GONE = "c".repeat(64);
const REF_DECEASED = "d".repeat(64);
const REF_UNKNOWN = "e".repeat(64);
const OPEN: FamilyChangeRequestTypes = { data: [{ type: "DEATH_REPORT" }], meta: { submission_enabled: true } };

function member(overrides: Partial<FamilyMember>): FamilyMember {
  return {
    member_ref: REF_GRANDMA,
    available: true,
    full_name: "جدّة الاختبار",
    relationship: null,
    is_household_head: false,
    gender: "FEMALE",
    birth_date: "1940-05-01",
    marital_status: "WIDOWED",
    life_status: "ALIVE",
    death_date: null,
    national_id_masked: "*****4567",
    mobile_masked: null,
    alternate_mobile_masked: null,
    alternate_mobile_owner_relation: null,
    membership_started_at: null,
    ...overrides,
  };
}

const MEMBERS = {
  data: {
    family_code: "FAM-TEST-1",
    members: [
      member({ member_ref: REF_HEAD, full_name: "رب الأسرة التجريبي", is_household_head: true, gender: "MALE", birth_date: "1970-01-01" }),
      member({}),
      member({ member_ref: REF_GONE, available: false, full_name: null }),
      member({ member_ref: REF_DECEASED, full_name: "جدّ متوفى", life_status: "DECEASED", death_date: "2025-01-01" }),
      member({ member_ref: REF_UNKNOWN, full_name: "عمّ غير معروف الحالة", life_status: "UNKNOWN" }),
    ],
  },
};

function mockGet({ types = OPEN as unknown, members = MEMBERS as unknown } = {}) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const value = path.endsWith("/types") ? types : path === "/api/v1/family/household/members" ? members : undefined;
    if (value instanceof Error) throw value;
    if (value === undefined) throw new Error(`unexpected GET ${path}`);
    return value;
  });
}

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyDeathReportForm />);
  await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
  return user;
}

async function pick(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
  await user.click(await screen.findByRole("option", { name }));
}

describe("availability", () => {
  it("is unavailable unless the server lists DEATH_REPORT", async () => {
    mockGet({ types: { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyDeathReportForm />);
    expect(await screen.findByRole("heading", { name: "الإبلاغ عن الوفاة غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
  });

  it("is offered from «طلب جديد» only when the server lists it", () => {
    expect(familyRequestFormFor("DEATH_REPORT")).toBe("/family/requests/new/death-report");
    expect(canStartFamilyRequest(OPEN)).toBe(true);
    expect(canStartFamilyRequest({ data: [], meta: { submission_enabled: true } })).toBe(false);
  });

  it("shows an error with retry", async () => {
    mockGet({ members: new ApiError(500, { message: "x" }) });
    renderWithClient(<FamilyDeathReportForm />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل النموذج");
  });

  it("explains when no member can be reported", async () => {
    mockGet({ members: { data: { family_code: "FAM-TEST-1", members: [MEMBERS.data.members[0]] } } });
    renderWithClient(<FamilyDeathReportForm />);
    expect(await screen.findByRole("heading", { name: "لا يوجد أفراد يمكن الإبلاغ عن وفاتهم" })).toBeInTheDocument();
  });
});

describe("the death report form", () => {
  it("lists only eligible members: never the head, never the deceased or unavailable", async () => {
    mockGet();
    const user = await renderForm();

    expect(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" })).toBeDisabled();
    await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
    const options = (await screen.findAllByRole("option")).map((o) => o.textContent);
    expect(options).toEqual(["جدّة الاختبار", "عمّ غير معروف الحالة"]);
  });

  it("submits the member_ref and the date of death, then opens the request", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: NEW_ID, request_code: "CRQ-000401", status: "SUBMITTED", replayed: false } });
    const user = await renderForm();

    await pick(user, "جدّة الاختبار");
    expect(screen.getByRole("region", { name: "بيانات الفرد في السجل" })).toHaveTextContent("1940-05-01");
    await user.type(screen.getByLabelText(/تاريخ الوفاة/, { selector: "input[type=date]" }), "2026-09-20");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/requests/${NEW_ID}`));
    expect(post.mock.calls[0][1]).toEqual({
      type: "DEATH_REPORT",
      client_reference: expect.stringMatching(/^[0-9a-f-]{36}$/),
      reason: null,
      data: { member_ref: REF_GRANDMA, death_date: "2026-09-20", death_date_unknown: false },
    });
    expect(JSON.stringify(post.mock.calls[0][1])).not.toMatch(/person_id|family_id|verification_method/);
  });

  it("sends an unknown date as null with the explicit choice — never an invented date", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: NEW_ID, request_code: "CRQ-000402", status: "SUBMITTED", replayed: false } });
    const user = await renderForm();

    await pick(user, "جدّة الاختبار");
    await user.type(screen.getByLabelText(/تاريخ الوفاة/, { selector: "input[type=date]" }), "2026-09-20");
    await user.click(screen.getByRole("checkbox", { name: "تاريخ الوفاة غير معروف" }));
    expect(screen.getByLabelText(/تاريخ الوفاة/, { selector: "input[type=date]" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect((post.mock.calls[0][1] as { data: unknown }).data).toEqual({ member_ref: REF_GRANDMA, death_date: null, death_date_unknown: true });
  });

  it("requires a date or the unknown choice, and shows the server's field errors and refusals", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { death_date: ["تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد."] } }))
      .mockRejectedValueOnce(new ApiError(409, { message: "x", code: "CHANGE_REQUEST_ALREADY_OPEN" }));
    const user = await renderForm();

    await pick(user, "جدّة الاختبار");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("أدخل تاريخ الوفاة، أو اختر «تاريخ الوفاة غير معروف».")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(/تاريخ الوفاة/, { selector: "input[type=date]" }), "1930-01-01");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/بلاغ وفاة مفتوح لهذا الفرد/)).toBeInTheDocument();
    expect(browserStorageDump()).not.toMatch(/جدّة/);
  });
});
